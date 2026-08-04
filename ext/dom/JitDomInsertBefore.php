<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::insertBefore() (#22686, #26458, #27449).
 *
 * Thin standalone AOT materializes createElement nodes without DomRegistry
 * ({@see JitDomCreateElement::materializeElementFromLiteral}). The NestedJIT
 * DomRegistry bridge then leaves LLVM childNodes/firstChild/parentNode stale —
 * mirror ParentNode::append / replaceChild slot sync (php-src ext/dom/node.c).
 */
final class JitDomInsertBefore
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::insertBefore() expects receiver and newChild');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_before_cont');

        // php-src: null refChild ≡ append (ext/dom/node.c). Reuse appendChild AOT path (#26458).
        if (
            \count($args) < 3
            || JITVariable::TYPE_NULL === $args[2]->type
            || $args[2]->isNullConstant
        ) {
            return JitDomAppendChild::invoke($context, $args[0], $args[1]);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            self::syncUserScriptInsertBeforeSlots($context, $args[0], $args[1], $args[2]);
            DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $args[1]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_before_post');

            return self::boxObjectResult($context, self::loadObjectArg($context, $args[1]));
        }

        DomNodeTreeMutationRuntime::ensureInsertBeforeLinked($context);

        $parent = self::loadObjectArg($context, $args[0]);
        $newChild = self::loadObjectArg($context, $args[1]);
        $refChild = self::loadObjectArg($context, $args[2]);
        $context->builder->call(
            $context->lookupFunction(DomNodeTreeMutationRuntime::ABI_INSERT_BEFORE),
            $parent,
            $newChild,
            $refChild
        );

        return self::boxObjectResult($context, $newChild);
    }

    /**
     * Update live tree LLVM slots for thin-AOT insertBefore (#27449).
     *
     * Skipping DomRegistry NestedJIT: unregistered createElement nodes leave
     * childNodes length / firstChild / parentNode unchanged after the call.
     */
    private static function syncUserScriptInsertBeforeSlots(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): void {
        $parent = self::loadObjectArg($context, $parentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $refChild = self::loadObjectArg($context, $refChildVar);
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');

        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([
            VmDom::PROP_FIRST_CHILD,
            VmDom::PROP_LAST_CHILD,
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_CHILD_NODES,
        ] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $refJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $refChild);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);

        // Sibling links: new ↔ ref (php-src xmlAddPrevSibling).
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMNode', VmDom::PROP_NEXT_SIBLING),
            $refJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($refChild, 'DOMNode', VmDom::PROP_PREVIOUS_SIBLING),
            $newJit,
            JITVariable::TYPE_VALUE
        );

        // firstChild ← newChild when inserting before the current first (issue repro).
        $firstSlot = $objectType->propertySlotFor($parent, 'DOMNode', VmDom::PROP_FIRST_CHILD);
        $firstPtr = $context->builder->load($firstSlot);
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstPtr, $voidPtr->constNull());
        $setFirst = BasicBlockHelper::append($context, 'dom_ib_set_first');
        $checkFirst = BasicBlockHelper::append($context, 'dom_ib_check_first');
        $afterFirst = BasicBlockHelper::append($context, 'dom_ib_after_first');
        $context->builder->branchIf($firstSlotNull, $setFirst, $checkFirst);

        $context->builder->positionAtEnd($checkFirst);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstPtr, $context->getTypeFromString('__value__*'))
        );
        $firstIsRef = $context->builder->icmp(Builder::INT_EQ, $firstObj, $refChild);
        $firstIsNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $shouldSetFirst = $context->builder->or($firstIsRef, $firstIsNull);
        $context->builder->branchIf($shouldSetFirst, $setFirst, $afterFirst);

        $context->builder->positionAtEnd($setFirst);
        $objectType->propertyStore($firstSlot, $newJit, JITVariable::TYPE_VALUE);
        $context->builder->branch($afterFirst);

        $context->builder->positionAtEnd($afterFirst);

        // parentNode on newChild (DOMElement allocation layout — #21687 / #27216).
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );

        // firstElementChild when inserting before current first element (#27449).
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_FIRST_ELEMENT_CHILD)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_FIRST_ELEMENT_CHILD, JITVariable::TYPE_VALUE);
        }
        $fecSlot = $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_FIRST_ELEMENT_CHILD);
        $fecPtr = $context->builder->load($fecSlot);
        $fecSlotNull = $context->builder->icmp(Builder::INT_EQ, $fecPtr, $voidPtr->constNull());
        $setFec = BasicBlockHelper::append($context, 'dom_ib_set_fec');
        $checkFec = BasicBlockHelper::append($context, 'dom_ib_check_fec');
        $afterFec = BasicBlockHelper::append($context, 'dom_ib_after_fec');
        $context->builder->branchIf($fecSlotNull, $setFec, $checkFec);

        $context->builder->positionAtEnd($checkFec);
        $fecObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($fecPtr, $context->getTypeFromString('__value__*'))
        );
        $fecIsRef = $context->builder->icmp(Builder::INT_EQ, $fecObj, $refChild);
        $fecIsNull = $context->builder->icmp(Builder::INT_EQ, $fecObj, $objPtrTy->constNull());
        $shouldSetFec = $context->builder->or($fecIsRef, $fecIsNull);
        $context->builder->branchIf($shouldSetFec, $setFec, $afterFec);

        $context->builder->positionAtEnd($setFec);
        $objectType->propertyStore($fecSlot, $newJit, JITVariable::TYPE_VALUE);
        $context->builder->branch($afterFec);

        $context->builder->positionAtEnd($afterFec);

        self::bumpChildNodesLength($context, $parent, $newChild, $refChild);
    }

    /**
     * Bump or seed parent.childNodes.length after insertBefore (#27449, peer #27044).
     *
     * Also pins __phpcItem0/1 for owner-aware item() when length becomes 2 (#27410).
     */
    private static function bumpChildNodesLength(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $refChild
    ): void {
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        foreach (['__phpcItem0', '__phpcItem1'] as $prop) {
            if (!$objectType->hasProperty($listClassId, $prop)) {
                $objectType->defineProperty($listClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $refJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $refChild);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);

        // Always replace the live list with length=2 + pinned items for insert-before-ref.
        // ParentNode::append stores childNodes as TYPE_VALUE; in-place TYPE_OBJECT bumps
        // miss that shape (#27044 / #27449). Fresh list matches Zend length after insert.
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(2, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $parentJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
            $refJit,
            JITVariable::TYPE_VALUE
        );
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMNode', VmDom::PROP_CHILD_NODES),
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

        throw new \LogicException('DOMNode::insertBefore() expects object nodes');
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
