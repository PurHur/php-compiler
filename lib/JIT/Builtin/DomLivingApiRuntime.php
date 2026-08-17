<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\DomConstants;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT link for DOM Living Standard methods (#19507, #21687, #25878).
 *
 * Bool ABI is int1 (DomLoadXML pattern). Lower call args before ensureBridge.
 * toggleAttribute uses omit / force-true / force-false ABIs (null force collapses in nested TUs).
 *
 * Thin standalone AOT: contains/getRootNode/isEqualNode/isSameNode/compareDocumentPosition via
 * LLVM parentNode/nextSibling/tagName slots (NestedJIT DomRegistry rematerialization loses
 * identity — php-src ext/dom/node.c).
 */
final class DomLivingApiRuntime
{
    public const ABI_CONTAINS = '__phpc_dom_living_contains';

    public const ABI_CONTAINS_NULL = '__phpc_dom_living_contains_null';

    public const ABI_COMPARE_DOCUMENT_POSITION = '__phpc_dom_living_compare_document_position';

    public const ABI_GET_ROOT_NODE = '__phpc_dom_living_get_root_node';

    public const ABI_IS_EQUAL_NODE = '__phpc_dom_living_is_equal_node';

    public const ABI_TOGGLE_ATTRIBUTE_OMIT = '__phpc_dom_living_toggle_omit';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE = '__phpc_dom_living_toggle_force_true';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE = '__phpc_dom_living_toggle_force_false';

    public static function invokeContains(Context $context, Variable $receiver, Variable $other): Value
    {
        // php-src stub ?DOMNode — compile-time null → false (#31791, peer invokeIsEqualNode / #24462).
        // Literal null often arrives as TYPE_VALUE + isNullConstant; the old TYPE_NULL-only
        // bridge path never ran, and containsViaParentSlots loadObject(null) segfaulted under AOT.
        if (Variable::TYPE_NULL === $other->type || $other->isNullConstant) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_contains_null_const');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::containsViaParentSlots($context, $receiver, $other);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureContainsBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            $receiverLlvm,
            $otherLlvm
        );
    }

    /**
     * DOMNode::contains via parentNode LLVM slots (#21687).
     * Walk other→…→parent looking for receiver (pointer identity).
     */
    private static function containsViaParentSlots(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_contains_slots');
        $i1 = $context->getTypeFromString('int1');
        $objPtr = $context->getTypeFromString('__object__*');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        $fn = $context->builder->getInsertBlock()->getParent();
        $hit = $fn->appendBasicBlock('dom_contains_hit');
        $miss = $fn->appendBasicBlock('dom_contains_miss');
        $done = $fn->appendBasicBlock('dom_contains_done');

        // Runtime null in a value box → false (php-src ?DOMNode); avoid GEP on null (#31791).
        $otherIsNull = $context->builder->icmp(
            Builder::INT_EQ,
            $otherLlvm,
            $objPtr->constNull()
        );
        $afterOtherNull = $fn->appendBasicBlock('dom_contains_after_other_null');
        $context->builder->branchIf($otherIsNull, $miss, $afterOtherNull);
        $context->builder->positionAtEnd($afterOtherNull);

        $same = $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
        $startWalk = $fn->appendBasicBlock('dom_contains_start');
        $context->builder->branchIf($same, $hit, $startWalk);

        $context->builder->positionAtEnd($startWalk);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');

        $current = $otherLlvm;
        for ($hop = 0; $hop < 8; ++$hop) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($current, $objMap['class_id'])
            );
            $isDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($docClassId, false)
            );
            $afterDoc = $fn->appendBasicBlock('dom_contains_d'.$hop);
            $context->builder->branchIf($isDoc, $miss, $afterDoc);
            $context->builder->positionAtEnd($afterDoc);

            $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $current,
                'DOMElement',
                VmDom::PROP_PARENT_NODE,
                $elementClassId
            );
            $parentRaw = JitValueBox::valuePtrFromVariable($context, $parentVar);
            $parentIsNull = JitNestedHelperCoerce::isHelperResultNull($context, $parentRaw);
            $afterNull = $fn->appendBasicBlock('dom_contains_n'.$hop);
            $context->builder->branchIf($parentIsNull, $miss, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $parentObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr($context, $parentRaw)
            );
            $parentObjNull = $context->builder->icmp(
                Builder::INT_EQ,
                $parentObj,
                $objPtr->constNull()
            );
            $afterObj = $fn->appendBasicBlock('dom_contains_o'.$hop);
            $context->builder->branchIf($parentObjNull, $miss, $afterObj);
            $context->builder->positionAtEnd($afterObj);
            $isHit = $context->builder->icmp(Builder::INT_EQ, $parentObj, $receiverLlvm);
            $cont = $fn->appendBasicBlock('dom_contains_c'.$hop);
            $context->builder->branchIf($isHit, $hit, $cont);
            $context->builder->positionAtEnd($cont);
            $current = $parentObj;
        }
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hit);
        $phi->addIncoming($i1->constInt(0, false), $miss);

        return $phi;
    }

    /**
     * DOMNode::compareDocumentPosition — php-src ext/dom/node.c (#25878).
     * Returns int64 bitmask (CONTAINED_BY|FOLLOWING when this contains other, etc.).
     */
    public static function invokeCompareDocumentPosition(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::compareDocumentPositionViaParentSlots($context, $receiver, $other);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureCompareDocumentPositionBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COMPARE_DOCUMENT_POSITION),
            $receiverLlvm,
            $otherLlvm
        );
    }

    /**
     * Thin AOT compareDocumentPosition via parentNode / nextSibling slots (#25878).
     * Ancestor axis = php-src steps 7–8; siblings via nextSibling under a common parent.
     */
    private static function compareDocumentPositionViaParentSlots(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        static $seq = 0;
        $id = (string) $seq++;
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cdp_slots_'.$id);
        $i64 = $context->getTypeFromString('int64');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        $fn = $context->builder->getInsertBlock()->getParent();
        $sameBb = $fn->appendBasicBlock('dom_cdp_same_'.$id);
        $checkAnc = $fn->appendBasicBlock('dom_cdp_anc_'.$id);
        $containedBy = $fn->appendBasicBlock('dom_cdp_contained_'.$id);
        $contains = $fn->appendBasicBlock('dom_cdp_contains_'.$id);
        $sib = $fn->appendBasicBlock('dom_cdp_sib_'.$id);
        $following = $fn->appendBasicBlock('dom_cdp_following_'.$id);
        $preceding = $fn->appendBasicBlock('dom_cdp_preceding_'.$id);
        $disconnected = $fn->appendBasicBlock('dom_cdp_disc_'.$id);
        $done = $fn->appendBasicBlock('dom_cdp_done_'.$id);
        $cont = $fn->appendBasicBlock('dom_cdp_cont_'.$id);

        $same = $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
        $context->builder->branchIf($same, $sameBb, $checkAnc);

        $context->builder->positionAtEnd($sameBb);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkAnc);
        $thisContainsOther = self::emitContainsWalk($context, $receiverLlvm, $otherLlvm, 'cdp_tco_'.$id);
        $afterTco = $fn->appendBasicBlock('dom_cdp_after_tco_'.$id);
        $context->builder->branchIf($thisContainsOther, $containedBy, $afterTco);

        $context->builder->positionAtEnd($afterTco);
        $otherContainsThis = self::emitContainsWalk($context, $otherLlvm, $receiverLlvm, 'cdp_oct_'.$id);
        $context->builder->branchIf($otherContainsThis, $contains, $sib);

        $context->builder->positionAtEnd($containedBy);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($contains);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sib);
        $sibOrder = self::emitSiblingDocumentOrder(
            $context,
            $receiverLlvm,
            $otherLlvm,
            'cdp_sib_'.$id
        );
        $isFollow = $context->builder->icmp(
            Builder::INT_EQ,
            $sibOrder,
            $i64->constInt(DomConstants::DOCUMENT_POSITION_FOLLOWING, false)
        );
        $afterFollow = $fn->appendBasicBlock('dom_cdp_af_'.$id);
        $context->builder->branchIf($isFollow, $following, $afterFollow);
        $context->builder->positionAtEnd($afterFollow);
        $isPrecede = $context->builder->icmp(
            Builder::INT_EQ,
            $sibOrder,
            $i64->constInt(DomConstants::DOCUMENT_POSITION_PRECEDING, false)
        );
        $context->builder->branchIf($isPrecede, $preceding, $disconnected);

        $context->builder->positionAtEnd($following);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($preceding);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($disconnected);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($i64->constInt(0, false), $sameBb);
        $phi->addIncoming(
            $i64->constInt(
                DomConstants::DOCUMENT_POSITION_CONTAINED_BY | DomConstants::DOCUMENT_POSITION_FOLLOWING,
                false
            ),
            $containedBy
        );
        $phi->addIncoming(
            $i64->constInt(
                DomConstants::DOCUMENT_POSITION_CONTAINS | DomConstants::DOCUMENT_POSITION_PRECEDING,
                false
            ),
            $contains
        );
        $phi->addIncoming($i64->constInt(DomConstants::DOCUMENT_POSITION_FOLLOWING, false), $following);
        $phi->addIncoming($i64->constInt(DomConstants::DOCUMENT_POSITION_PRECEDING, false), $preceding);
        $phi->addIncoming(
            $i64->constInt(
                DomConstants::DOCUMENT_POSITION_DISCONNECTED
                | DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC
                | DomConstants::DOCUMENT_POSITION_PRECEDING,
                false
            ),
            $disconnected
        );
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);

        return $phi;
    }

    /** Walk $descendant→parent looking for $ancestor; returns i1. */
    private static function emitContainsWalk(
        Context $context,
        Value $ancestor,
        Value $descendant,
        string $tag
    ): Value {
        $fn = $context->builder->getInsertBlock()->getParent();
        $i1 = $context->getTypeFromString('int1');
        $objPtr = $context->getTypeFromString('__object__*');
        $hit = $fn->appendBasicBlock('dom_cw_hit_'.$tag);
        $miss = $fn->appendBasicBlock('dom_cw_miss_'.$tag);
        $done = $fn->appendBasicBlock('dom_cw_done_'.$tag);
        $start = $fn->appendBasicBlock('dom_cw_start_'.$tag);

        $same = $context->builder->icmp(Builder::INT_EQ, $ancestor, $descendant);
        $context->builder->branchIf($same, $hit, $start);

        $context->builder->positionAtEnd($start);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');

        $current = $descendant;
        for ($hop = 0; $hop < 8; ++$hop) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($current, $objMap['class_id'])
            );
            $isDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($docClassId, false)
            );
            $afterDoc = $fn->appendBasicBlock('dom_cw_d_'.$tag.'_'.$hop);
            $context->builder->branchIf($isDoc, $miss, $afterDoc);
            $context->builder->positionAtEnd($afterDoc);

            $parentObj = self::loadLinkedObject($context, $current, $elementClassId, VmDom::PROP_PARENT_NODE, 'lpo_'.$tag.'_'.$hop);
            $parentObjNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtr->constNull());
            $afterObj = $fn->appendBasicBlock('dom_cw_o_'.$tag.'_'.$hop);
            $context->builder->branchIf($parentObjNull, $miss, $afterObj);
            $context->builder->positionAtEnd($afterObj);
            $isHit = $context->builder->icmp(Builder::INT_EQ, $parentObj, $ancestor);
            $contHop = $fn->appendBasicBlock('dom_cw_c_'.$tag.'_'.$hop);
            $context->builder->branchIf($isHit, $hit, $contHop);
            $context->builder->positionAtEnd($contHop);
            $current = $parentObj;
        }
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hit);
        $phi->addIncoming($i1->constInt(0, false), $miss);

        return $phi;
    }

    /**
     * Document-order among non-ancestor nodes via nextSibling + parent climb.
     * Returns i64 FOLLOWING (4), PRECEDING (2), or 0 if unresolved.
     */
    private static function emitSiblingDocumentOrder(
        Context $context,
        Value $nodeA,
        Value $nodeB,
        string $tag
    ): Value {
        $fn = $context->builder->getInsertBlock()->getParent();
        $i64 = $context->getTypeFromString('int64');
        $objPtr = $context->getTypeFromString('__object__*');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_NEXT_ELEMENT_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, Variable::TYPE_VALUE);
            }
        }

        $followBb = $fn->appendBasicBlock('dom_sdo_follow_'.$tag);
        $precedeBb = $fn->appendBasicBlock('dom_sdo_precede_'.$tag);
        $unknownBb = $fn->appendBasicBlock('dom_sdo_unknown_'.$tag);
        $done = $fn->appendBasicBlock('dom_sdo_done_'.$tag);

        $curA = $nodeA;
        $curB = $nodeB;
        for ($level = 0; $level < 8; ++$level) {
            $foundFollow = self::emitNextSiblingFind($context, $curA, $curB, 'sdo_f_'.$tag.'_'.$level);
            $afterF = $fn->appendBasicBlock('dom_sdo_af_'.$tag.'_'.$level);
            $context->builder->branchIf($foundFollow, $followBb, $afterF);
            $context->builder->positionAtEnd($afterF);

            $foundPrecede = self::emitNextSiblingFind($context, $curB, $curA, 'sdo_p_'.$tag.'_'.$level);
            $afterP = $fn->appendBasicBlock('dom_sdo_ap_'.$tag.'_'.$level);
            $context->builder->branchIf($foundPrecede, $precedeBb, $afterP);
            $context->builder->positionAtEnd($afterP);

            $parentA = self::loadLinkedObject($context, $curA, $elementClassId, VmDom::PROP_PARENT_NODE, 'sdo_pa_'.$tag.'_'.$level);
            $parentB = self::loadLinkedObject($context, $curB, $elementClassId, VmDom::PROP_PARENT_NODE, 'sdo_pb_'.$tag.'_'.$level);
            $aNull = $context->builder->icmp(Builder::INT_EQ, $parentA, $objPtr->constNull());
            $bNull = $context->builder->icmp(Builder::INT_EQ, $parentB, $objPtr->constNull());
            $eitherNull = $context->builder->or($aNull, $bNull);
            $afterNull = $fn->appendBasicBlock('dom_sdo_an_'.$tag.'_'.$level);
            $context->builder->branchIf($eitherNull, $unknownBb, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $curA = $parentA;
            $curB = $parentB;
        }
        $context->builder->branch($unknownBb);

        $context->builder->positionAtEnd($followBb);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($precedeBb);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($unknownBb);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($i64->constInt(DomConstants::DOCUMENT_POSITION_FOLLOWING, false), $followBb);
        $phi->addIncoming($i64->constInt(DomConstants::DOCUMENT_POSITION_PRECEDING, false), $precedeBb);
        $phi->addIncoming($i64->constInt(0, false), $unknownBb);

        return $phi;
    }

    /** Walk nextSibling from $start looking for $target; returns i1. */
    private static function emitNextSiblingFind(
        Context $context,
        Value $start,
        Value $target,
        string $tag
    ): Value {
        $fn = $context->builder->getInsertBlock()->getParent();
        $i1 = $context->getTypeFromString('int1');
        $objPtr = $context->getTypeFromString('__object__*');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NEXT_ELEMENT_SIBLING)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NEXT_ELEMENT_SIBLING, Variable::TYPE_VALUE);
        }

        $hit = $fn->appendBasicBlock('dom_nsf_hit_'.$tag);
        $miss = $fn->appendBasicBlock('dom_nsf_miss_'.$tag);
        $done = $fn->appendBasicBlock('dom_nsf_done_'.$tag);

        $current = $start;
        for ($hop = 0; $hop < 16; ++$hop) {
            $next = self::loadLinkedObject($context, $current, $elementClassId, VmDom::PROP_NEXT_ELEMENT_SIBLING, 'nsf_'.$tag.'_'.$hop);
            $isNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtr->constNull());
            $afterNull = $fn->appendBasicBlock('dom_nsf_n_'.$tag.'_'.$hop);
            $context->builder->branchIf($isNull, $miss, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $isHit = $context->builder->icmp(Builder::INT_EQ, $next, $target);
            $contHop = $fn->appendBasicBlock('dom_nsf_c_'.$tag.'_'.$hop);
            $context->builder->branchIf($isHit, $hit, $contHop);
            $context->builder->positionAtEnd($contHop);
            $current = $next;
        }
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hit);
        $phi->addIncoming($i1->constInt(0, false), $miss);

        return $phi;
    }

    /** Load a nullable object-valued DOMElement property slot as __object__*. */
    private static function loadLinkedObject(
        Context $context,
        Value $obj,
        int $elementClassId,
        string $prop,
        string $tag
    ): Value {
        $objectType = $context->type->object;
        $propVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            'DOMElement',
            $prop,
            $elementClassId
        );
        $propRaw = JitValueBox::valuePtrFromVariable($context, $propVar);
        $objPtr = $context->getTypeFromString('__object__*');
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $propRaw);
        $fn = $context->builder->getInsertBlock()->getParent();
        $nullBb = $fn->appendBasicBlock('dom_ll_null_'.$tag);
        $objBb = $fn->appendBasicBlock('dom_ll_obj_'.$tag);
        $done = $fn->appendBasicBlock('dom_ll_done_'.$tag);
        $context->builder->branchIf($isNull, $nullBb, $objBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($objBb);
        $linked = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $propRaw)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($objPtr->constNull(), $nullBb);
        $phi->addIncoming($linked, $objBb);

        return $phi;
    }

    public static function invokeGetRootNode(Context $context, Variable $receiver): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // Return raw __object__* (same ABI as createElement materialize). Boxing into
            // __value__* makes `$root === $doc` compile as string-vs-value and abort (#21687).
            return self::getRootNodeViaParentSlots($context, $receiver);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_GET_ROOT_NODE),
            $receiverLlvm
        );
    }

    /**
     * DOMNode::getRootNode (#21687, #21766).
     * Walk parentNode until null; return topmost node (php-src ext/dom/node.c dom_get_root_node).
     */
    private static function getRootNodeViaParentSlots(Context $context, Variable $receiver): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_get_root_slots');
        $objPtr = $context->getTypeFromString('__object__*');
        $current = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }

        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('dom_root_done');
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $docClassId = $objectType->lookup('DOMDocument');
        /** @var list<array{0: \PHPLLVM\BasicBlock, 1: Value}> */
        $stopIncomings = [];

        for ($hop = 0; $hop < 8; ++$hop) {
            $stopHere = $fn->appendBasicBlock('dom_root_stop'.$hop);
            $cont = $fn->appendBasicBlock('dom_root_cont'.$hop);

            $classIdVal = $context->builder->load(
                $context->builder->structGep($current, $objMap['class_id'])
            );
            $isDoc = $context->builder->icmp(
                Builder::INT_EQ,
                $classIdVal,
                $i64->constInt($docClassId, false)
            );
            $afterDoc = $fn->appendBasicBlock('dom_root_d'.$hop);
            $context->builder->branchIf($isDoc, $stopHere, $afterDoc);
            $context->builder->positionAtEnd($afterDoc);

            $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $current,
                'DOMElement',
                VmDom::PROP_PARENT_NODE,
                $elementClassId
            );
            $parentRaw = JitValueBox::valuePtrFromVariable($context, $parentVar);
            $parentIsNull = JitNestedHelperCoerce::isHelperResultNull($context, $parentRaw);
            $afterNull = $fn->appendBasicBlock('dom_root_n'.$hop);
            $context->builder->branchIf($parentIsNull, $stopHere, $afterNull);
            $context->builder->positionAtEnd($afterNull);
            $parentObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr($context, $parentRaw)
            );
            $parentObjNull = $context->builder->icmp(
                Builder::INT_EQ,
                $parentObj,
                $objPtr->constNull()
            );
            $afterObj = $fn->appendBasicBlock('dom_root_o'.$hop);
            $context->builder->branchIf($parentObjNull, $stopHere, $afterObj);
            $context->builder->positionAtEnd($afterObj);
            $context->builder->branch($cont);

            $context->builder->positionAtEnd($stopHere);
            $context->builder->branch($done);
            $stopIncomings[] = [$stopHere, $current];

            $context->builder->positionAtEnd($cont);
            $current = $parentObj;
        }

        $fallthrough = $fn->appendBasicBlock('dom_root_fall');
        $context->builder->branch($fallthrough);
        $context->builder->positionAtEnd($fallthrough);
        $context->builder->branch($done);
        $stopIncomings[] = [$fallthrough, $current];

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($objPtr);
        foreach ($stopIncomings as [$block, $value]) {
            $phi->addIncoming($value, $block);
        }

        return $phi;
    }

    public static function invokeIsSameNode(Context $context, Variable $receiver, Variable $other): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_issame_slots');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        return $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
    }

    public static function invokeIsEqualNode(Context $context, Variable $receiver, Variable $other): Value
    {
        // php-src stub ?DOMNode — compile-time null → false (#24462, ext/dom/node.c).
        if (Variable::TYPE_NULL === $other->type || $other->isNullConstant) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_isequal_null_const');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::isEqualNodeViaTagName($context, $receiver, $other);
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_EQUAL_NODE),
            $receiverLlvm,
            $otherLlvm
        );
    }

    /**
     * Thin AOT isEqualNode: pointer identity or equal tagName (#21687).
     * Sufficient for leaf elements created via createElement (no DomRegistry).
     *
     * Distinct true-path blocks (pointer vs tag) + continuation after the phi so a
     * prior self-compare cannot leave the insert point inside the phi block (#24973).
     */
    private static function isEqualNodeViaTagName(
        Context $context,
        Variable $receiver,
        Variable $other
    ): Value {
        static $seq = 0;
        $id = (string) $seq++;
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_isequal_slots_'.$id);
        $i1 = $context->getTypeFromString('int1');
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);

        $fn = $context->builder->getInsertBlock()->getParent();
        $hitPtr = $fn->appendBasicBlock('dom_isequal_hit_ptr_'.$id);
        $hitTag = $fn->appendBasicBlock('dom_isequal_hit_tag_'.$id);
        $miss = $fn->appendBasicBlock('dom_isequal_miss_'.$id);
        $cmpTags = $fn->appendBasicBlock('dom_isequal_tags_'.$id);
        $done = $fn->appendBasicBlock('dom_isequal_done_'.$id);
        $cont = $fn->appendBasicBlock('dom_isequal_cont_'.$id);

        $same = $context->builder->icmp(Builder::INT_EQ, $receiverLlvm, $otherLlvm);
        $context->builder->branchIf($same, $hitPtr, $cmpTags);

        $context->builder->positionAtEnd($cmpTags);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', Variable::TYPE_STRING);
        }
        $tagA = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $receiverLlvm,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagB = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $otherLlvm,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $strA = $context->helper->loadValue($tagA);
        $strB = $context->helper->loadValue($tagB);
        $cmp = JitStringCompare::strcmp($context, $strA, $strB);
        $i64 = $context->getTypeFromString('int64');
        $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $context->builder->branchIf($eq, $hitTag, $miss);

        $context->builder->positionAtEnd($hitPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($hitTag);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $hitPtr);
        $phi->addIncoming($i1->constInt(1, false), $hitTag);
        $phi->addIncoming($i1->constInt(0, false), $miss);
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);

        return $phi;
    }

    public static function invokeToggleAttribute(
        Context $context,
        Variable $receiver,
        Variable $name,
        ?Variable $force
    ): Value {
        $nameLlvm = JitStringArg::lower($context, $name, 'DOMElement::toggleAttribute() name');
        $receiverLlvm = self::loadObject($context, $receiver);
        $abi = self::ABI_TOGGLE_ATTRIBUTE_OMIT;
        if (null !== $force && Variable::TYPE_NULL !== $force->type) {
            if (Variable::TYPE_NATIVE_BOOL === $force->type) {
                $raw = $context->helper->loadValue($force);
                if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                    $abi = ((int) $raw->getConstantValue() !== 0)
                        ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                        : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
                }
            } elseif (Variable::TYPE_NATIVE_LONG === $force->type && null !== $force->compileTimeLong) {
                $abi = (0 !== $force->compileTimeLong)
                    ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                    : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
            }
        }
        if (self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceTrueBridge($context);
        } elseif (self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceFalseBridge($context);
        } else {
            JitDomDocumentMethodKernel::ensureToggleAttributeOmitBridge($context);
        }

        return $context->builder->call(
            $context->lookupFunction($abi),
            $receiverLlvm,
            $nameLlvm
        );
    }

    private static function loadObject(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOM living API arg must be object or value box');
    }
}
