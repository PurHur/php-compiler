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
 * LLVM lowering for DOMElement::setIdAttribute{,NS,Node}() (#29257, #29284, #33957).
 *
 * NestedJIT helper updates DomRegistry without PROP_ELEMENT_ID_MAP sync. Thin AOT
 * stores {@see DomUserScriptElementCacheLlvm} from a **runtime** getAttribute after
 * setId so getElementById sees the receiver's id value — not the first `id=` in the
 * loadXML literal (#33957).
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
            // Prefer per-element compile-time attrs (firstChild/nextSibling stamps) over the
            // global name→value Attr cache — that cache returns the last id= in the doc (#34050).
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            $elemAttrVal = self::compileTimeReceiverAttrValue($args[0], $nameLit);
            if (null !== $elemAttrVal && '' !== $elemAttrVal) {
                $idStr = $context->builder->load($context->constantStringFromString($elemAttrVal));
                self::storeCacheFromRuntimeIdString($context, $element, $idStr);
            } else {
                // Live Attr cache value — NestedJIT getAttribute is empty after loadXML (#33957).
                self::storeCacheFromRuntimeGetAttribute($context, $element, $nameLlvm, $nameLit);
            }
            if (null !== $nameLit && '' !== $nameLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $nameLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
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
            $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            $elemAttrVal = self::compileTimeReceiverAttrValue($args[0], $localLit);
            if (null !== $elemAttrVal && '' !== $elemAttrVal) {
                $idStr = $context->builder->load($context->constantStringFromString($elemAttrVal));
                self::storeCacheFromRuntimeIdString($context, $element, $idStr);
            } else {
                // getAttribute(localName) live cache — DomRegistry empty after loadXML (#33957).
                self::storeCacheFromRuntimeGetAttribute($context, $element, $localLlvm, $localLit);
            }
            $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString ?? '';
            if (null !== $localLit && '' !== $localLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral((string) $nsLit, $localLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
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
            self::storeCacheFromRuntimeAttrValue($context, $element, $attr);
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
        }

        return self::boxNull($context);
    }

    /**
     * Thin-AOT getElementById cache from the live Attr value (#33957).
     *
     * Prefer NestedJIT / live Attr object over {@see DomUserScriptAttributeCacheLlvm::literalValue}:
     * that map is keyed only by attribute name, so multiple id= values collapse to the last
     * seed and firstChild setIdAttribute registers the wrong id (#34050).
     */
    private static function storeCacheFromRuntimeGetAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm,
        ?string $nameLit = null
    ): void {
        if (null !== $nameLit && '' !== $nameLit) {
            $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
            $objPtr = $context->getTypeFromString('__object__*');
            $isNull = $context->builder->icmp(Builder::INT_EQ, $attr, $objPtr->constNull());
            $miss = BasicBlockHelper::append($context, 'dom_setid_live_miss');
            $hit = BasicBlockHelper::append($context, 'dom_setid_live_hit');
            $done = BasicBlockHelper::append($context, 'dom_setid_live_done');
            $context->builder->branchIf($isNull, $miss, $hit);

            $context->builder->positionAtEnd($miss);
            self::storeCacheFromNestedGetAttribute($context, $element, $nameLlvm);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($hit);
            $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
            JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
            $valueVar = $context->type->object->propertyFetch($attr, $attrClass, 'value');
            self::storeCacheFromRuntimeIdString($context, $element, $context->helper->loadValue($valueVar));
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);

            return;
        }
        self::storeCacheFromNestedGetAttribute($context, $element, $nameLlvm);
    }

    private static function storeCacheFromNestedGetAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm
    ): void {
        JitDomDocumentMethodKernel::ensureGetAttributeBridge($context);
        $idStr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
            $element,
            $nameLlvm
        );
        self::storeCacheFromRuntimeIdString($context, $element, $idStr);
    }

    /** setIdAttributeNode — Attr::$value is the id string (#33957). */
    private static function storeCacheFromRuntimeAttrValue(
        Context $context,
        Value $element,
        Value $attr
    ): void {
        $objectType = $context->type->object;
        $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
        JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        $attrClassId = $objectType->lookup($attrClass);
        if (!$objectType->hasProperty($attrClassId, 'value')) {
            $objectType->defineProperty($attrClassId, 'value', JITVariable::TYPE_STRING);
        }
        $valueVar = \PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $attr,
            $attrClass,
            'value',
            $attrClassId
        );
        self::storeCacheFromRuntimeIdString($context, $element, $context->helper->loadValue($valueVar));
    }

    private static function storeCacheFromRuntimeIdString(
        Context $context,
        Value $element,
        Value $idStr
    ): void {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($idStr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $storeBlock = BasicBlockHelper::append($context, 'dom_setid_rt_store');
        $cont = BasicBlockHelper::append($context, 'dom_setid_rt_cont');
        $context->builder->branchIf($nonEmpty, $storeBlock, $cont);

        $context->builder->positionAtEnd($storeBlock);
        // xmlAddID first-wins for loadXML child-edge setIdAttribute (#34050).
        // createElement/setAttribute path must overwrite the single-slot cache (issue_29257).
        if (null !== JitDomNodeChildProperty::$lastFetchedAttributes) {
            DomUserScriptElementCacheLlvm::storeFirstWins($context, $element, $idStr, $element);
        } else {
            DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);
        }
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);
    }

    /**
     * Attribute value from the receiver's loadXML open-tag stamp (#34050).
     *
     * ARG_SEND / method temps often drop {@see JITVariable::$compileTimeDomAttributes};
     * fall back to {@see JitDomNodeChildProperty::$lastFetchedAttributes} (cleared on
     * createElement / documentElement so it cannot leak across documents).
     */
    private static function compileTimeReceiverAttrValue(JITVariable $receiver, ?string $nameLit): ?string
    {
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        $attrs = $receiver->compileTimeDomAttributes;
        if (null === $attrs || [] === $attrs) {
            $attrs = JitDomNodeChildProperty::$lastFetchedAttributes;
        }
        if (null === $attrs || [] === $attrs) {
            return null;
        }
        if (isset($attrs[$nameLit]) && '' !== $attrs[$nameLit]) {
            return $attrs[$nameLit];
        }
        $pos = strpos($nameLit, ':');
        if (false !== $pos) {
            $local = substr($nameLit, $pos + 1);
            if (isset($attrs[$local]) && '' !== $attrs[$local]) {
                return $attrs[$local];
            }
        }

        return null;
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
