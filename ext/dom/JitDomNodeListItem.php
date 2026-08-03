<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeListItemRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for DOMNodeList::item() (#18493, #27410). */
final class JitDomNodeListItem
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNodeList::item() expects receiver and index');
        }

        if (JitDomNodeListItemUserScript::shouldUse($context)) {
            $us = JitDomNodeListItemUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomNodeListItemRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_call_cont');

        // Thin-AOT: owner-aware item() (pinned __phpcItemN / firstChild|lastChild) (#27410).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeOwnerAware($context, $args[0], $args[1]);
        }

        $nodeList = self::loadObjectArg($context, $args[0]);
        $index = self::loadIntArg($context, $args[1]);
        $result = $context->builder->call(
            $context->lookupFunction(DomNodeListItemRuntime::ABI_NAME),
            $nodeList,
            $index
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_post_call');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return JitValueBox::normalizeValuePtr($context, $result);
        }

        return $result;
    }

    private static function invokeOwnerAware(
        Context $context,
        JITVariable $listVar,
        JITVariable $indexVar
    ): Value {
        $objectType = $context->type->object;
        $list = self::loadObjectArg($context, $listVar);
        $index = self::loadIntArg($context, $indexVar);
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $listClassId = $objectType->lookup('DOMNodeList');
        foreach (['__phpcItem0', '__phpcItem1', VmDom::PROP_CHILD_NODES_OWNER] as $prop) {
            if (!$objectType->hasProperty($listClassId, $prop)) {
                $objectType->defineProperty($listClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $ownerSlot = $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER);
        $ownerPtr = $context->builder->load($ownerSlot);
        $noOwner = $context->builder->icmp(Builder::INT_EQ, $ownerPtr, $voidPtr->constNull());

        $helperBlock = BasicBlockHelper::append($context, 'dom_nli_helper');
        $ownerBlock = BasicBlockHelper::append($context, 'dom_nli_owner');
        $merge = BasicBlockHelper::append($context, 'dom_nli_merge');
        $context->builder->branchIf($noOwner, $helperBlock, $ownerBlock);

        $context->builder->positionAtEnd($helperBlock);
        $helperRaw = $context->builder->call(
            $context->lookupFunction(DomNodeListItemRuntime::ABI_NAME),
            $list,
            $index
        );
        $helperVal = JitValueBox::normalizeValuePtr($context, $helperRaw);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($ownerBlock);
        $owner = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($ownerPtr, $valuePtrTy)
        );
        $is0 = $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt(0, false));
        $is1 = $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt(1, false));
        $use0 = BasicBlockHelper::append($context, 'dom_nli_idx0');
        $check1 = BasicBlockHelper::append($context, 'dom_nli_check1');
        $use1 = BasicBlockHelper::append($context, 'dom_nli_idx1');
        $ownerHelper = BasicBlockHelper::append($context, 'dom_nli_owner_helper');
        $context->builder->branchIf($is0, $use0, $check1);
        $context->builder->positionAtEnd($check1);
        $context->builder->branchIf($is1, $use1, $ownerHelper);

        // index 0: __phpcItem0 else owner.firstChild
        $context->builder->positionAtEnd($use0);
        $exit0 = self::emitItemResolve(
            $context,
            $list,
            $owner,
            '__phpcItem0',
            VmDom::PROP_FIRST_CHILD,
            $merge,
            $voidPtr,
            $valuePtrTy,
            $objPtrTy
        );

        // index 1: __phpcItem1 else owner.lastChild
        $context->builder->positionAtEnd($use1);
        $exit1 = self::emitItemResolve(
            $context,
            $list,
            $owner,
            '__phpcItem1',
            VmDom::PROP_LAST_CHILD,
            $merge,
            $voidPtr,
            $valuePtrTy,
            $objPtrTy
        );

        $context->builder->positionAtEnd($ownerHelper);
        $ownerHelperRaw = $context->builder->call(
            $context->lookupFunction(DomNodeListItemRuntime::ABI_NAME),
            $list,
            $index
        );
        $ownerHelperVal = JitValueBox::normalizeValuePtr($context, $ownerHelperRaw);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($helperVal, $helperBlock);
        $phi->addIncoming($exit0['value'], $exit0['block']);
        $phi->addIncoming($exit1['value'], $exit1['block']);
        $phi->addIncoming($ownerHelperVal, $ownerHelper);

        return $phi;
    }

    /**
     * Write result into a fresh value box from pin or owner child; branch to $merge.
     *
     * @return array{value: Value, block: mixed}
     */
    private static function emitItemResolve(
        Context $context,
        Value $list,
        Value $owner,
        string $pinProp,
        string $ownerChildProp,
        $merge,
        $voidPtr,
        $valuePtrTy,
        $objPtrTy
    ): array {
        $objectType = $context->type->object;
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $exit = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_done');

        $pinRaw = $context->builder->load(
            $objectType->propertySlotFor($list, 'DOMNodeList', $pinProp)
        );
        $pinSlotNull = $context->builder->icmp(Builder::INT_EQ, $pinRaw, $voidPtr->constNull());
        $readPin = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_read');
        $fallback = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_fb');
        $usePin = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_use');
        $context->builder->branchIf($pinSlotNull, $fallback, $readPin);

        $context->builder->positionAtEnd($readPin);
        $pinObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($pinRaw, $valuePtrTy)
        );
        $pinObjNull = $context->builder->icmp(Builder::INT_EQ, $pinObj, $objPtrTy->constNull());
        $context->builder->branchIf($pinObjNull, $fallback, $usePin);

        $context->builder->positionAtEnd($usePin);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $pinObj
        );
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($fallback);
        $childRaw = $context->builder->load(
            $objectType->propertySlotFor($owner, 'DOMNode', $ownerChildProp)
        );
        $childSlotNull = $context->builder->icmp(Builder::INT_EQ, $childRaw, $voidPtr->constNull());
        $writeNull = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_null');
        $readChild = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_child');
        $writeChild = BasicBlockHelper::append($context, 'dom_nli_'.$pinProp.'_write');
        $context->builder->branchIf($childSlotNull, $writeNull, $readChild);

        $context->builder->positionAtEnd($writeNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($readChild);
        $childObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($childRaw, $valuePtrTy)
        );
        $childObjNull = $context->builder->icmp(Builder::INT_EQ, $childObj, $objPtrTy->constNull());
        $context->builder->branchIf($childObjNull, $writeNull, $writeChild);

        $context->builder->positionAtEnd($writeChild);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $childObj
        );
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($exit);
        $val = JitValueBox::normalizeValuePtr($context, $resultPtr);
        $context->builder->branch($merge);

        return ['value' => $val, 'block' => $exit];
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

        throw new \LogicException('DOMNodeList::item() receiver must be an object');
    }

    private static function loadIntArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNodeList::item() index must be an integer');
    }
}
