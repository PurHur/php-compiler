<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMNodeList::item() (#18493, #26752). */
final class JitDomNodeListItemUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $index = $args[1]->compileTimeLong;
        if (null === $index && null !== $args[1]->compileTimeString && is_numeric($args[1]->compileTimeString)) {
            $index = (int) $args[1]->compileTimeString;
        }
        if (null === $index || 0 !== $index) {
            return null;
        }

        // XPath evaluate/query cache hit.
        $cacheKey = JitDomXPathQueryUserScript::lastCacheKey();
        if (null !== $cacheKey) {
            $keyStr = $context->builder->load($context->constantStringFromString($cacheKey));
            $found = DomUserScriptElementCacheLlvm::lookupObject($context, $keyStr);

            return self::boxObject($context, $found);
        }

        // getElementsByTagName live list: return pinned root firstChild (#26752).
        if (null === JitDomLoadXMLUserScript::lastCompileTimeXml()
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            return null;
        }
        $pinned = DomUserScriptPinnedRootLlvm::load($context);
        if (null === $pinned) {
            return null;
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_us_first');
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_FIRST_CHILD)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_FIRST_CHILD, JITVariable::TYPE_VALUE);
        }
        $firstObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $pinned,
            VmDom::PROP_FIRST_CHILD
        );

        return self::boxObject($context, $firstObj);
    }

    private static function loadChildObjectFromSlot(
        Context $context,
        Object_ $objectType,
        Value $receiver,
        string $prop
    ): Value {
        $childSlot = $objectType->propertySlotFor($receiver, 'DOMNode', $prop);
        $slotPtr = $context->builder->load($childSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_read');
        $merge = BasicBlockHelper::append($context, 'dom_nli_us_'.$prop.'_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $childObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($childObj, $readBlock);

        return $phi;
    }

    private static function boxObject(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
