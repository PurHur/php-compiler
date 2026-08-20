<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
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

        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::insertBefore', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

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

    public static function syncUserScriptInsertBeforeSlotsPublic(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): void {
        self::syncUserScriptInsertBeforeSlots($context, $parentVar, $newChildVar, $refChildVar);
    }

    public static function bumpChildNodesLengthPublic(
        Context $context,
        Value $parent,
        Value $item0,
        Value $item1
    ): void {
        self::bumpChildNodesLength($context, $parent, $item0, $item1);
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
        // Wrong Document / Hierarchy Request before LiveSlots (#30274).
        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            $parent,
            $newChild
        );
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');

        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $refJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $refChild);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);

        JitDomParentChildLinkLayout::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, $refJit);
        JitDomParentChildLinkLayout::storeSibling($context, $refChild, VmDom::PROP_PREVIOUS_SIBLING, $newJit);

        // firstChild ← newChild when inserting before the current first (#32611: DOMDocument uses DOMNode).
        $firstObj = JitDomParentChildLinkLayout::loadFirstChild($context, $parent, 'dom_ib');
        $firstIsRef = $context->builder->icmp(Builder::INT_EQ, $firstObj, $refChild);
        $firstIsNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $shouldSetFirst = $context->builder->or($firstIsRef, $firstIsNull);
        $setFirst = BasicBlockHelper::append($context, 'dom_ib_set_first');
        $afterFirst = BasicBlockHelper::append($context, 'dom_ib_after_first');
        $context->builder->branchIf($shouldSetFirst, $setFirst, $afterFirst);

        $context->builder->positionAtEnd($setFirst);
        JitDomParentChildLinkLayout::storeFirstChild($context, $parent, $newJit);
        $context->builder->branch($afterFirst);

        $context->builder->positionAtEnd($afterFirst);

        JitDomParentChildLinkLayout::storeParentNode($context, $newChild, $parentJit);

        // firstElementChild when inserting before current first element (#27449).
        $fecObj = JitDomParentChildLinkLayout::loadFirstElementChild($context, $parent, 'dom_ib');
        $fecIsRef = $context->builder->icmp(Builder::INT_EQ, $fecObj, $refChild);
        $fecIsNull = $context->builder->icmp(Builder::INT_EQ, $fecObj, $objPtrTy->constNull());
        $shouldSetFec = $context->builder->or($fecIsRef, $fecIsNull);
        $setFec = BasicBlockHelper::append($context, 'dom_ib_set_fec');
        $afterFec = BasicBlockHelper::append($context, 'dom_ib_after_fec');
        $context->builder->branchIf($shouldSetFec, $setFec, $afterFec);

        $context->builder->positionAtEnd($setFec);
        JitDomParentChildLinkLayout::storeFirstElementChild($context, $parent, $newJit);
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
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_CHILD_NODES),
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
