<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** User-script AOT DOMDocument::appendChild() — documentElement store (#18927, #21687). */
final class JitDomAppendChildUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function invokeDocumentAppend(
        Context $context,
        JITVariable $documentVar,
        JITVariable $childVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_store_cont');

        $document = self::loadObjectArg($context, $documentVar);
        $child = self::loadObjectArg($context, $childVar);
        $objectType = $context->type->object;
        $childJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $child);

        // #27410: zero old parent's live childNodes length before reparent.
        self::zeroOldParentChildNodesLength($context, $child);

        // documentElement: keep existing root; only first append installs it (#27410).
        // loadXML/loadHTML pin documentElement as TYPE_OBJECT (raw __object__*), not a
        // VALUE box — __value__readObject on that pointer segfaults (#29487 / re-#19212).
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, self::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, self::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $docElSlot = $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, self::PROP_DOCUMENT_ELEMENT);
        $docElPtr = $context->builder->load($docElSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $docElPtr, $voidPtr->constNull());
        $checkVal = BasicBlockHelper::append($context, 'dom_doc_ac_de_check');
        $setRoot = BasicBlockHelper::append($context, 'dom_doc_ac_set_de');
        $linkNext = BasicBlockHelper::append($context, 'dom_doc_ac_link_next');
        $afterDe = BasicBlockHelper::append($context, 'dom_doc_ac_after_de');
        $context->builder->branchIf($slotNull, $setRoot, $checkVal);

        $context->builder->positionAtEnd($checkVal);
        $existingRoot = $context->builder->pointerCast($docElPtr, $objPtrTy);
        $rootNull = $context->builder->icmp(Builder::INT_EQ, $existingRoot, $objPtrTy->constNull());
        $context->builder->branchIf($rootNull, $setRoot, $linkNext);

        $context->builder->positionAtEnd($setRoot);
        $objectType->propertyStore($docElSlot, $childJit, JITVariable::TYPE_OBJECT);
        self::ensureChildLinkLayout($context);
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        self::writeChildNodesListWithOwner($context, $document, 1, $child, null);
        $context->builder->branch($afterDe);

        $context->builder->positionAtEnd($linkNext);
        self::ensureChildLinkLayout($context);
        // Prefer lastChild; fall back to documentElement as prior sibling.
        $lastSlot = $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_LAST_CHILD);
        $lastPtr = $context->builder->load($lastSlot);
        $lastSlotNull = $context->builder->icmp(Builder::INT_EQ, $lastPtr, $voidPtr->constNull());
        $useRoot = BasicBlockHelper::append($context, 'dom_doc_ac_tail_root');
        $useLast = BasicBlockHelper::append($context, 'dom_doc_ac_tail_last');
        $haveTail = BasicBlockHelper::append($context, 'dom_doc_ac_have_tail');
        $context->builder->branchIf($lastSlotNull, $useRoot, $useLast);
        $context->builder->positionAtEnd($useRoot);
        $context->builder->branch($haveTail);
        $context->builder->positionAtEnd($useLast);
        $lastObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($lastPtr, $context->getTypeFromString('__value__*'))
        );
        $lastObjNull = $context->builder->icmp(Builder::INT_EQ, $lastObj, $objPtrTy->constNull());
        $context->builder->branchIf($lastObjNull, $useRoot, $haveTail);
        $context->builder->positionAtEnd($haveTail);
        $tailPhi = $context->builder->phi($objPtrTy);
        $tailPhi->addIncoming($existingRoot, $useRoot);
        $tailPhi->addIncoming($lastObj, $useLast);
        $objectType->propertyStore(
            $objectType->propertySlotFor($tailPhi, 'DOMNode', VmDom::PROP_NEXT_SIBLING),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        self::writeChildNodesListWithOwner($context, $document, 2, $existingRoot, $child);
        $context->builder->branch($afterDe);

        $context->builder->positionAtEnd($afterDe);

        // #21687: parentNode on child via DOMElement layout (allocation class).
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        // Pin for document-wide saveXML() without loadXML (#32361).
        DomUserScriptPinnedRootLlvm::pin($context, $child);

        return self::boxObjectResult($context, $child);
    }

    /** Replace old parent's childNodes with length 0 when child.parentNode is set (#27410). */
    private static function zeroOldParentChildNodesLength(Context $context, Value $child): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }

        $parentSlot = $objectType->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($parentSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slotIsNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $skip = BasicBlockHelper::append($context, 'dom_doc_ac_skip_unlink');
        $read = BasicBlockHelper::append($context, 'dom_doc_ac_read_parent');
        $do = BasicBlockHelper::append($context, 'dom_doc_ac_unlink');
        $merge = BasicBlockHelper::append($context, 'dom_doc_ac_unlink_merge');
        $context->builder->branchIf($slotIsNull, $skip, $read);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($merge);

        // Null-tagged VALUE slots are non-null pointers — readObject then check (#27410).
        $context->builder->positionAtEnd($read);
        $oldParent = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($slotPtr, $context->getTypeFromString('__value__*'))
        );
        $parentIsNull = $context->builder->icmp(Builder::INT_EQ, $oldParent, $objPtrTy->constNull());
        $context->builder->branchIf($parentIsNull, $skip, $do);

        $context->builder->positionAtEnd($do);
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($oldParent, 'DOMNode', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function ensureChildLinkLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
    }

    private static function writeChildNodesListWithOwner(
        Context $context,
        Value $owner,
        int $length,
        ?Value $item0 = null,
        ?Value $item1 = null
    ): void {
        self::ensureChildLinkLayout($context);
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($listClassId, '__phpcItem0')) {
            $objectType->defineProperty($listClassId, '__phpcItem0', JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, '__phpcItem1')) {
            $objectType->defineProperty($listClassId, '__phpcItem1', JITVariable::TYPE_VALUE);
        }
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        if (null !== $item0) {
            $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
                $i0,
                JITVariable::TYPE_VALUE
            );
        }
        if (null !== $item1) {
            $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
                $i1,
                JITVariable::TYPE_VALUE
            );
        }
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, 'DOMNode', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
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

        throw new \LogicException('DOMDocument::appendChild() expects object nodes');
    }

    private static function boxObjectResult(Context $context, Value $object): Value
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
