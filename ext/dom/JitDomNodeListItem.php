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
    /**
     * Last compile-time item($N) index — replaceChild ARG_SEND temps often lose
     * {@see JITVariable::$compileTimeDomChildIndex} (#32903 / peer firstChild #28671).
     */
    public static ?int $lastFetchedChildIndex = null;

    public static ?string $lastFetchedTagName = null;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNodeList::item() expects receiver and index');
        }

        self::rememberCompileTimeChildIndex($context, $args[1]);

        if (JitDomNodeListItemUserScript::shouldUse($context)) {
            $us = JitDomNodeListItemUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomNodeListItemRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_call_cont');

        // Thin-AOT: owner-aware item() (pinned __phpcItemN / firstChild|second) (#27410 / #32784).
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

    /**
     * Record item($N) when $N is an LLVM i64 constant (#32903 / #32831).
     *
     * Also mirrors onto {@see JitDomNodeChildProperty} statics so ChildNode /
     * replaceChild InnerXml helpers share one fallback.
     */
    private static function rememberCompileTimeChildIndex(Context $context, JITVariable $indexArg): void
    {
        $index = null;
        if (
            null !== $indexArg->value
            && \PHPLLVM\Value::KIND_CONSTANT_INT === $indexArg->value->getKind()
        ) {
            $index = $indexArg->compileTimeLong;
            if (null === $index && null !== $indexArg->compileTimeString && is_numeric($indexArg->compileTimeString)) {
                $index = (int) $indexArg->compileTimeString;
            }
            if (null === $index) {
                $index = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($indexArg->value->value);
            }
        }
        if (null === $index || $index < 0) {
            return;
        }
        self::$lastFetchedChildIndex = $index;
        JitDomNodeChildProperty::$lastFetchedChildIndex = $index;

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (
            null === $xml
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        if (!isset($nodes[$index]) || 'element' !== ($nodes[$index]['kind'] ?? null)) {
            return;
        }
        $tag = $nodes[$index]['data'] ?? null;
        if (null === $tag || '' === $tag) {
            return;
        }
        self::$lastFetchedTagName = $tag;
        JitDomNodeChildProperty::$lastFetchedTagName = $tag;
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
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
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
        $ownerWalk = BasicBlockHelper::append($context, 'dom_nli_owner_walk');
        $context->builder->branchIf($is0, $use0, $check1);
        $context->builder->positionAtEnd($check1);
        $context->builder->branchIf($is1, $use1, $ownerWalk);

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

        // index 1: __phpcItem1 else firstChild->nextSibling — NOT lastChild (#32784).
        $context->builder->positionAtEnd($use1);
        $exit1 = self::emitItemResolveSecond(
            $context,
            $list,
            $owner,
            $merge,
            $voidPtr,
            $valuePtrTy,
            $objPtrTy
        );

        // index >= 2: walk firstChild→nextSibling (ABI aborts on thin-AOT nodes, #32784).
        $context->builder->positionAtEnd($ownerWalk);
        $exitWalk = self::emitItemResolveWalk(
            $context,
            $owner,
            $index,
            $merge,
            $voidPtr,
            $valuePtrTy,
            $objPtrTy,
            $i64
        );

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($helperVal, $helperBlock);
        $phi->addIncoming($exit0['value'], $exit0['block']);
        $phi->addIncoming($exit1['value'], $exit1['block']);
        $phi->addIncoming($exitWalk['value'], $exitWalk['block']);

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
            $objectType->propertySlotFor($owner, 'DOMElement', $ownerChildProp)
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

    /**
     * index 1: __phpcItem1 else firstChild->nextSibling (#32784).
     *
     * Prior fallback used lastChild, which only matches index 1 when length==2.
     *
     * @return array{value: Value, block: mixed}
     */
    private static function emitItemResolveSecond(
        Context $context,
        Value $list,
        Value $owner,
        $merge,
        $voidPtr,
        $valuePtrTy,
        $objPtrTy
    ): array {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NEXT_SIBLING)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NEXT_SIBLING, JITVariable::TYPE_VALUE);
        }
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $exit = BasicBlockHelper::append($context, 'dom_nli_item1_done');
        $writeNull = BasicBlockHelper::append($context, 'dom_nli_item1_null');
        $readPin = BasicBlockHelper::append($context, 'dom_nli_item1_read');
        $fallback = BasicBlockHelper::append($context, 'dom_nli_item1_fb');
        $usePin = BasicBlockHelper::append($context, 'dom_nli_item1_use');
        $readFirst = BasicBlockHelper::append($context, 'dom_nli_item1_first');
        $readNext = BasicBlockHelper::append($context, 'dom_nli_item1_next');
        $storeNext = BasicBlockHelper::append($context, 'dom_nli_item1_store');

        $pinRaw = $context->builder->load(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1')
        );
        $pinSlotNull = $context->builder->icmp(Builder::INT_EQ, $pinRaw, $voidPtr->constNull());
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

        // Fallback: owner.firstChild->nextSibling (second child, not lastChild).
        $context->builder->positionAtEnd($fallback);
        $firstRaw = $context->builder->load(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_FIRST_CHILD)
        );
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstRaw, $voidPtr->constNull());
        $context->builder->branchIf($firstSlotNull, $writeNull, $readFirst);

        $context->builder->positionAtEnd($readFirst);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstRaw, $valuePtrTy)
        );
        $firstObjNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $context->builder->branchIf($firstObjNull, $writeNull, $readNext);

        $context->builder->positionAtEnd($readNext);
        $nextRaw = $context->builder->load(
            $objectType->propertySlotFor($firstObj, 'DOMElement', VmDom::PROP_NEXT_SIBLING)
        );
        $nextSlotNull = $context->builder->icmp(Builder::INT_EQ, $nextRaw, $voidPtr->constNull());
        $context->builder->branchIf($nextSlotNull, $writeNull, $storeNext);

        $context->builder->positionAtEnd($storeNext);
        $nextObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($nextRaw, $valuePtrTy)
        );
        $nextObjNull = $context->builder->icmp(Builder::INT_EQ, $nextObj, $objPtrTy->constNull());
        $doStore = BasicBlockHelper::append($context, 'dom_nli_item1_write_obj');
        $context->builder->branchIf($nextObjNull, $writeNull, $doStore);
        $context->builder->positionAtEnd($doStore);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $nextObj
        );
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($writeNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($exit);
        $val = JitValueBox::normalizeValuePtr($context, $resultPtr);
        $context->builder->branch($merge);

        return ['value' => $val, 'block' => $exit];
    }

    /**
     * Walk owner.firstChild → nextSibling until index (#32784).
     *
     * Thin-AOT nodes are not in DomRegistry, so the NestedJIT ABI aborts on
     * index >= 2 — walk sibling slots in LLVM instead.
     *
     * @return array{value: Value, block: mixed}
     */
    private static function emitItemResolveWalk(
        Context $context,
        Value $owner,
        Value $index,
        $merge,
        $voidPtr,
        $valuePtrTy,
        $objPtrTy,
        $i64
    ): array {
        $objectType = $context->type->object;
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $exit = BasicBlockHelper::append($context, 'dom_nli_walk_done');
        $writeNull = BasicBlockHelper::append($context, 'dom_nli_walk_null');
        $readFirst = BasicBlockHelper::append($context, 'dom_nli_walk_read_first');
        $loopHeader = BasicBlockHelper::append($context, 'dom_nli_walk_hdr');
        $advance = BasicBlockHelper::append($context, 'dom_nli_walk_adv');
        $found = BasicBlockHelper::append($context, 'dom_nli_walk_found');

        $firstRaw = $context->builder->load(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_FIRST_CHILD)
        );
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstRaw, $voidPtr->constNull());
        $context->builder->branchIf($firstSlotNull, $writeNull, $readFirst);

        $context->builder->positionAtEnd($readFirst);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstRaw, $valuePtrTy)
        );
        $firstObjNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $enter = BasicBlockHelper::append($context, 'dom_nli_walk_enter');
        $context->builder->branchIf($firstObjNull, $writeNull, $enter);
        $enterPred = $enter;
        $context->builder->positionAtEnd($enter);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($loopHeader);
        $curPhi = $context->builder->phi($objPtrTy);
        $idxPhi = $context->builder->phi($i64);
        $atIndex = $context->builder->icmp(Builder::INT_EQ, $idxPhi, $index);
        $context->builder->branchIf($atIndex, $found, $advance);

        $context->builder->positionAtEnd($advance);
        $nextRaw = $context->builder->load(
            $objectType->propertySlotFor($curPhi, 'DOMElement', VmDom::PROP_NEXT_SIBLING)
        );
        $nextSlotNull = $context->builder->icmp(Builder::INT_EQ, $nextRaw, $voidPtr->constNull());
        $readNext = BasicBlockHelper::append($context, 'dom_nli_walk_read_next');
        $context->builder->branchIf($nextSlotNull, $writeNull, $readNext);

        $context->builder->positionAtEnd($readNext);
        $nextObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($nextRaw, $valuePtrTy)
        );
        $nextObjNull = $context->builder->icmp(Builder::INT_EQ, $nextObj, $objPtrTy->constNull());
        $back = BasicBlockHelper::append($context, 'dom_nli_walk_back');
        $context->builder->branchIf($nextObjNull, $writeNull, $back);
        $context->builder->positionAtEnd($back);
        $nextIdx = $context->builder->add($idxPhi, $i64->constInt(1, false));
        $context->builder->branch($loopHeader);

        // Complete phis now that predecessors exist
        $curPhi->addIncoming($firstObj, $enterPred);
        $curPhi->addIncoming($nextObj, $back);
        $idxPhi->addIncoming($i64->constInt(0, false), $enterPred);
        $idxPhi->addIncoming($nextIdx, $back);

        $context->builder->positionAtEnd($found);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $curPhi
        );
        $context->builder->branch($exit);

        $context->builder->positionAtEnd($writeNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
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
