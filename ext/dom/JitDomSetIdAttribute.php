<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::setIdAttribute{,NS,Node}() (#29257, #29284).
 *
 * NestedJIT helper updates DomRegistry without PROP_ELEMENT_ID_MAP sync. Thin AOT
 * stores {@see DomUserScriptElementCacheLlvm} from runtime getAttribute (not the first
 * id= in the loadXML literal) so setIdAttribute on a later sibling registers the
 * correct id (#33957). setAttribute('id', …) history still drives the #29694 skip.
 *
 * setAttribute reusing an id already in the compile-time loadXML literal skips/clears
 * the cache — xmlAddID first-wins after replaceChild (#29694 / re-#25274).
 */
final class JitDomSetIdAttribute
{
    /** @var list<string> Compile-time id attribute values from setAttribute('id', …). */
    private static array $setAttributeIdValues = [];

    public static function rememberSetAttributeIdValue(string $value): void
    {
        self::$setAttributeIdValues[] = $value;
    }

    public static function resetCompileTimeState(): void
    {
        self::$setAttributeIdValues = [];
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setIdAttribute() expects receiver, name, and isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_cont');
        $nameLlvm = JitStringArg::lower($context, $args[1], 'DOMElement::setIdAttribute() name');
        $element = self::loadObjectArg($context, $args[0]);
        $isIdTrue = self::resolveIsIdTrue($context, $args[2]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_FALSE;
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $nameLlvm
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_post');
            self::storeCacheAfterSetIdAttribute($context, $element, $nameLlvm, $args[1]);
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $nameLit && '' !== $nameLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $nameLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $nameLit && '' !== $nameLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $nameLit, false);
            }
        }

        return self::boxNull($context);
    }

    public static function invokeNs(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 4) {
            throw new \LogicException('DOMElement::setIdAttributeNS() expects receiver, namespace, localName, isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_ns_cont');
        $nsLlvm = self::loadNamespaceArg($context, $args[1]);
        $localLlvm = JitStringArg::lower($context, $args[2], 'DOMElement::setIdAttributeNS() localName');
        $element = self::loadObjectArg($context, $args[0]);
        $isIdTrue = self::resolveIsIdTrue($context, $args[3]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureNsTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NS_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureNsFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NS_FALSE;
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $nsLlvm,
            $localLlvm
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_ns_post');
            self::storeCacheAfterSetIdAttribute($context, $element, $localLlvm, $args[2]);
            $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString ?? '';
            $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            if (null !== $localLit && '' !== $localLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral((string) $nsLit, $localLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString ?? '';
            $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            if (null !== $localLit && '' !== $localLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral((string) $nsLit, $localLit, false);
            }
        }

        return self::boxNull($context);
    }

    public static function invokeNode(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setIdAttributeNode() expects receiver, attr, and isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_node_cont');

        // php-src Z_PARAM_OBJ_OF_CLASS(DOMAttr) — null must TypeError, not silent no-op (#33758).
        if (JitDomRequireDomNodeArg::guardOrAbort(
            $context,
            $args[1],
            'DOMElement::setIdAttributeNode',
            1,
            'attr',
            'DOMAttr'
        )) {
            return self::boxNull($context);
        }

        $element = self::loadObjectArg($context, $args[0]);
        $attr = self::loadObjectArg($context, $args[1]);
        $isIdTrue = self::resolveIsIdTrue($context, $args[2]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureNodeTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NODE_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureNodeFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NODE_FALSE;
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $attr
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_node_post');
            // Node form: attribute name is typically "id"; apply the same multi-id rule.
            if (self::countAttrAssignmentsInLoadXmlLiteral('id') > 1) {
                $idName = $context->builder->load($context->constantStringFromString('id'));
                self::storeCacheFromRuntimeGetAttribute($context, $element, $idName);
            } else {
                $fromSetAttribute = false;
                $idLit = self::resolveCompileTimeNodeIdValue($fromSetAttribute);
                if (null !== $idLit && '' !== $idLit) {
                    self::storeCacheIfElementOwnsId($context, $element, $idLit, $fromSetAttribute);
                }
            }
        }


        return self::boxNull($context);
    }

    /** NestedJIT bool load is unsafe — only constant false selects the false ABI (#29257). */
    private static function resolveIsIdTrue(Context $context, JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL !== $arg->type) {
            return true;
        }
        $raw = $context->helper->loadValue($arg);
        if (
            method_exists($raw, 'isConstant')
            && $raw->isConstant()
            && method_exists($raw, 'getConstantValue')
            && (int) $raw->getConstantValue() === 0
        ) {
            return false;
        }

        return true;
    }

    private static function resolveCompileTimeIdValue(JITVariable $nameArg, bool &$fromSetAttribute): ?string
    {
        $fromSetAttribute = false;
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($nameArg) ?? $nameArg->compileTimeString;
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        // Prefer last setAttribute($name, $value) — createElement paths (#29257).
        if ([] !== self::$setAttributeIdValues && 'id' === $nameLit) {
            $fromSetAttribute = true;

        $idLit = self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        // First id="…" / id='…' or localName="…" in the loadXML literal (first-wins).
        if (1 === preg_match(
            '/\b'.preg_quote($nameLit, '/').'\s*=\s*(["\'])([^"\']*)\1/',
            $xml,
            $m
        )) {
            return $m[2];
        }

        return null;
    }

    private static function resolveCompileTimeNodeIdValue(bool &$fromSetAttribute): ?string
    {
        $fromSetAttribute = false;
        if ([] !== self::$setAttributeIdValues) {
            $fromSetAttribute = true;

        $idLit = self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        if (1 === preg_match('/\bid\s*=\s*(["\'])([^"\']*)\1/', $xml, $m)) {
            return $m[2];
        }

        return null;
    }

    /**
     * Gate thin-AOT id-cache stores on compile-time duplicate-id proof (#29694).
     *
     * setAttribute('id', $v) when loadXML already contained id="$v" matches php-src
     * xmlAddID first-wins (replaceChild same id): DomRegistry rejects the new
     * registration, so the LLVM cache must not claim the replacement — including
     * on cache miss after a different id overwrote the single-slot cache.
     *
     * @param bool $fromSetAttribute True when the id value came from setAttribute('id', …).
     */
    /**
     * Thin-AOT id-cache update after NestedJIT setIdAttribute* (#33957).
     *
     * Multi-id loadXML literals must not first-wins via regex — read the element's
     * live attribute with getAttribute. Single-id / setAttribute paths keep the
     * prior compile-time store (avoids getAttribute on paths that already work).
     */
    private static function storeCacheAfterSetIdAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm,
        JITVariable $nameArg
    ): void {
        if (self::shouldSkipCacheForSetAttributeReuse($nameArg)) {
            return;
        }
        $fromSetAttribute = false;
        $idLit = self::resolveCompileTimeIdValue($nameArg, $fromSetAttribute);
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($nameArg) ?? $nameArg->compileTimeString;
        $multiId = null !== $nameLit && self::countAttrAssignmentsInLoadXmlLiteral($nameLit) > 1;
        if ($multiId) {
            // First-wins loadXML regex binds the wrong sibling (#33957). Prefer the id on the
            // getElementsByTagName(...)->item(N) target when that compile-time pair is known.
            $resolved = self::resolveMultiIdAttributeValue($nameLit ?? 'id');
            if (null !== $resolved && '' !== $resolved) {
                self::storeCacheFirstWins($context, $element, $resolved, true);

                return;
            }
            // Fallback: runtime getAttribute with a fresh "id" literal.
            self::storeCacheFromRuntimeGetAttribute($context, $element, $nameLlvm);

            return;
        }
        if (null !== $idLit && '' !== $idLit) {
            self::storeCacheIfElementOwnsId($context, $element, $idLit, $fromSetAttribute);
        }
    }

    /**
     * Multi-id loadXML: resolve attribute value for getElementsByTagName($tag)->item($i)
     * using the last compile-time tag query + item index (#33957).
     */
    private static function resolveMultiIdAttributeValue(string $nameLit): ?string
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $index = JitDomNodeListItem::$lastFetchedChildIndex;
        if (null === $tag || '' === $tag || '*' === $tag || null === $index || $index < 0) {
            return null;
        }

        return DomParseSimpleXmlJitHelper::nthTagAttributeValueArgv($xml, $tag, $nameLit, $index + 1);
    }

    private static function isFromSetAttributeIdName(JITVariable $nameArg, bool $allowNonIdName = false): bool
    {
        if ([] === self::$setAttributeIdValues) {
            return false;
        }
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($nameArg) ?? $nameArg->compileTimeString;
        if (null === $nameLit || '' === $nameLit) {
            return $allowNonIdName;
        }

        return $allowNonIdName || 'id' === $nameLit;
    }

    /**
     * #29694: setAttribute('id', $v) when loadXML already had id="$v" — DomRegistry
     * rejects the new registration; do not claim the id in the thin-AOT cache.
     */
    private static function shouldSkipCacheForSetAttributeReuse(JITVariable $nameArg, bool $allowNonIdName = false): bool
    {
        if (!self::isFromSetAttributeIdName($nameArg, $allowNonIdName)) {
            return false;
        }
        $idLit = self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];

        return '' !== $idLit && self::loadXmlLiteralAlreadyDefinesId($idLit);
    }

    /** Count name="…" / name='…' assignments in the compile-time loadXML literal. */
    private static function countAttrAssignmentsInLoadXmlLiteral(string $nameLit): int
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return 0;
        }
        $pattern = '/\b' . preg_quote($nameLit, '/') . '\s*=\s*(["\'])([^"\']*)\1/';
        if (0 === preg_match_all($pattern, $xml, $m)) {
            return 0;
        }

        return \count($m[0]);
    }

    /**
     * Key thin-AOT element cache by live getAttribute value (#33957).
     */
    private static function storeCacheFromRuntimeGetAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm
    ): void {
        DomImportNodeRuntime::ensureGetAttributeLinked($context);
        // Always pass a fresh "id" literal — reusing $nameLlvm after NestedJIT can be spent (#33957).
        $nameFresh = $context->builder->load($context->constantStringFromString('id'));
        $idStr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
            $element,
            $nameFresh
        );
        DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);
    }


    private static function storeCacheIfElementOwnsId(
        Context $context,
        Value $element,
        string $idLit,
        bool $fromSetAttribute
    ): void {
        if ($fromSetAttribute && self::loadXmlLiteralAlreadyDefinesId($idLit)) {
            return;
        }
        // setAttribute-sourced fresh ids overwrite; loadXML-resolved ids are first-wins.
        self::storeCacheFirstWins($context, $element, $idLit, $fromSetAttribute);
    }

    /** True when compile-time loadXML text already has this id attribute value. */
    private static function loadXmlLiteralAlreadyDefinesId(string $idLit): bool
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return false;
        }

        return 1 === preg_match(
            '/\bid\s*=\s*(["\'])'.preg_quote($idLit, '/').'\1/',
            $xml
        );
    }

    private static function storeCacheFirstWins(
        Context $context,
        Value $element,
        string $idLit,
        bool $overwrite
    ): void {
        $idStr = $context->builder->load($context->constantStringFromString($idLit));
        if ($overwrite) {
            DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);

            return;
        }
        $cached = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
        $objPtr = $context->getTypeFromString('__object__*');
        $hasHit = $context->builder->icmp(Builder::INT_NE, $cached, $objPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        $storeBlock = $fn->appendBasicBlock('dom_setid_cache_store');
        $cont = $fn->appendBasicBlock('dom_setid_cache_cont');
        // First registration wins — do not replace an existing cache entry for this id.
        $context->builder->branchIf($hasHit, $cont, $storeBlock);

        $context->builder->positionAtEnd($storeBlock);
        DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);
    }

    private static function loadNamespaceArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringArg::lower($context, $arg, 'DOMElement::setIdAttributeNS() namespace');
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMElement::setIdAttribute*() expects an object receiver/attr');
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
