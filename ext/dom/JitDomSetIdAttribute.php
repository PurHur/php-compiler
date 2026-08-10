<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
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
 * stores {@see DomUserScriptElementCacheLlvm} using a compile-time id value (from
 * loadXML literal or a preceding setAttribute('id', …)) so getElementById avoids
 * reading an uninitialized id map after loadXML.
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
            $fromSetAttribute = false;
            $idLit = self::resolveCompileTimeIdValue($args[1], $fromSetAttribute);
            if (null !== $idLit && '' !== $idLit) {
                self::storeCacheIfElementOwnsId($context, $element, $idLit, $fromSetAttribute);
            }
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
            $fromSetAttribute = false;
            $idLit = self::resolveCompileTimeIdValue($args[2], $fromSetAttribute);
            if (null !== $idLit && '' !== $idLit) {
                self::storeCacheIfElementOwnsId($context, $element, $idLit, $fromSetAttribute);
            }
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
            $fromSetAttribute = false;
            $idLit = self::resolveCompileTimeNodeIdValue($fromSetAttribute);
            if (null !== $idLit && '' !== $idLit) {
                self::storeCacheIfElementOwnsId($context, $element, $idLit, $fromSetAttribute);
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

            return self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];
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

            return self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];
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
