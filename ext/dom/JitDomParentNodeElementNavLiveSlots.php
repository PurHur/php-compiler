<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ParentNode element-nav slot sync after mutate (#35010).
 *
 * loadXML seeds first/lastElementChild + childElementCount (#34910). LiveSlots for
 * removeChild / insertBefore / replaceChild updated firstChild/childNodes but left
 * ParentNode element-nav stale (php-src ext/dom/parentnode.c).
 *
 * Peer createElement empty-nav SIGSEGV is #35007; this helper is for already-seeded trees.
 */
final class JitDomParentNodeElementNavLiveSlots
{
    private static int $seq = 0;

    private static function tag(string $prefix): string
    {
        return $prefix.'_'.(string) (++self::$seq);
    }

    public static function ensureProps(Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $docClassId = $objectType->lookup('DOMDocument');
        foreach ([
            VmDom::PROP_FIRST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_LAST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_NEXT_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PREVIOUS_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_CHILD_ELEMENT_COUNT => JITVariable::TYPE_NATIVE_LONG,
        ] as $prop => $type) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, $type);
            }
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, $type);
            }
        }
    }

    /**
     * After removeChild unlink: if $child is an element, update parent FEC/LEC/count
     * and rewire next/previousElementSibling on neighbors.
     */
    public static function afterRemove(Context $context, Value $parent, Value $child): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_pn_rm'));
        self::ensureProps($context);
        $bbDone = BasicBlockHelper::append($context, self::tag('dom_pn_rm_done'));
        // Document ParentNode is computed from documentElement (#34910) — peer appendChild #35007.
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, self::tag('dom_pn_rm_doc'));
        $bbAfterDoc = BasicBlockHelper::append($context, self::tag('dom_pn_rm_after_doc'));
        $context->builder->branchIf($isDoc, $bbDone, $bbAfterDoc);

        $context->builder->positionAtEnd($bbAfterDoc);
        $bbSkip = BasicBlockHelper::append($context, self::tag('dom_pn_rm_skip'));
        $bbDo = BasicBlockHelper::append($context, self::tag('dom_pn_rm_do'));
        $context->builder->branchIf(self::isElementObject($context, $child), $bbDo, $bbSkip);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDo);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);
        $nextEl = self::loadElementEdge($context, $child, VmDom::PROP_NEXT_ELEMENT_SIBLING, self::tag('dom_pn_rm_next'));
        $prevEl = self::loadElementEdge($context, $child, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::tag('dom_pn_rm_prev'));
        $fec = JitDomParentChildLinkLayout::loadFirstElementChild($context, $parent, self::tag('dom_pn_rm_fec'));
        $lec = self::loadLastElementChild($context, $parent, self::tag('dom_pn_rm_lec'));

        $bbSetFec = BasicBlockHelper::append($context, self::tag('dom_pn_rm_set_fec'));
        $bbAfterFec = BasicBlockHelper::append($context, self::tag('dom_pn_rm_after_fec'));
        $fecIsChild = $context->builder->icmp(Builder::INT_EQ, $fec, $child);
        $context->builder->branchIf($fecIsChild, $bbSetFec, $bbAfterFec);
        $context->builder->positionAtEnd($bbSetFec);
        JitDomParentChildLinkLayout::storeFirstElementChild(
            $context,
            $parent,
            self::objectOrNullVar($context, $nextEl)
        );
        $context->builder->branch($bbAfterFec);

        $context->builder->positionAtEnd($bbAfterFec);
        $bbSetLec = BasicBlockHelper::append($context, self::tag('dom_pn_rm_set_lec'));
        $bbAfterLec = BasicBlockHelper::append($context, self::tag('dom_pn_rm_after_lec'));
        $lecIsChild = $context->builder->icmp(Builder::INT_EQ, $lec, $child);
        $context->builder->branchIf($lecIsChild, $bbSetLec, $bbAfterLec);
        $context->builder->positionAtEnd($bbSetLec);
        self::storeLastElementChild($context, $parent, self::objectOrNullVar($context, $prevEl));
        $context->builder->branch($bbAfterLec);

        $context->builder->positionAtEnd($bbAfterLec);
        // prev.nextElementSibling = next; next.previousElementSibling = prev
        $bbPrevLink = BasicBlockHelper::append($context, self::tag('dom_pn_rm_prev_link'));
        $bbAfterPrev = BasicBlockHelper::append($context, self::tag('dom_pn_rm_after_prev'));
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prevEl, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeElementEdge($context, $prevEl, VmDom::PROP_NEXT_ELEMENT_SIBLING, self::objectOrNullVar($context, $nextEl));
        $context->builder->branch($bbAfterPrev);

        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, self::tag('dom_pn_rm_next_link'));
        $bbAfterNext = BasicBlockHelper::append($context, self::tag('dom_pn_rm_after_next'));
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextEl, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeElementEdge($context, $nextEl, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::objectOrNullVar($context, $prevEl));
        $context->builder->branch($bbAfterNext);

        $context->builder->positionAtEnd($bbAfterNext);
        self::storeElementEdge($context, $child, VmDom::PROP_NEXT_ELEMENT_SIBLING, $nullBox);
        self::storeElementEdge($context, $child, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, $nullBox);
        self::adjustChildElementCount($context, $parent, -1);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * After insertBefore of an element child: bump childElementCount and wire
     * element-sibling links; update lastElementChild when inserting after the prior last.
     */
    public static function afterInsertElement(Context $context, Value $parent, Value $newChild, Value $refChild): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_pn_ib'));
        self::ensureProps($context);
        $bbSkip = BasicBlockHelper::append($context, self::tag('dom_pn_ib_skip'));
        $bbDo = BasicBlockHelper::append($context, self::tag('dom_pn_ib_do'));
        $bbDone = BasicBlockHelper::append($context, self::tag('dom_pn_ib_done'));
        $context->builder->branchIf(self::isElementObject($context, $newChild), $bbDo, $bbSkip);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDo);
        self::adjustChildElementCount($context, $parent, 1);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);

        // When inserting before current last element (ref == LEC), new becomes previous of LEC;
        // LEC unchanged. When ref is not an element, find prev element via FEC walk is heavy —
        // if LEC is null, new is the only element → LEC = new (FEC path may already set FEC).
        $lec = self::loadLastElementChild($context, $parent, self::tag('dom_pn_ib_lec'));
        $bbSetLecNull = BasicBlockHelper::append($context, self::tag('dom_pn_ib_lec_null'));
        $bbAfterLecNull = BasicBlockHelper::append($context, self::tag('dom_pn_ib_after_lec_null'));
        $lecNull = $context->builder->icmp(Builder::INT_EQ, $lec, $objPtrTy->constNull());
        $context->builder->branchIf($lecNull, $bbSetLecNull, $bbAfterLecNull);
        $context->builder->positionAtEnd($bbSetLecNull);
        self::storeLastElementChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterLecNull);

        $context->builder->positionAtEnd($bbAfterLecNull);
        // ref is element ⇒ prevEl of ref becomes new; new.next = ref; ref.prev = new
        $bbRefEl = BasicBlockHelper::append($context, self::tag('dom_pn_ib_ref_el'));
        $bbAfterRef = BasicBlockHelper::append($context, self::tag('dom_pn_ib_after_ref'));
        $context->builder->branchIf(self::isElementObject($context, $refChild), $bbRefEl, $bbAfterRef);

        $context->builder->positionAtEnd($bbRefEl);
        $prevOfRef = self::loadElementEdge($context, $refChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::tag('dom_pn_ib_pref'));
        $bbPrevLink = BasicBlockHelper::append($context, self::tag('dom_pn_ib_prev_link'));
        $bbAfterPrev = BasicBlockHelper::append($context, self::tag('dom_pn_ib_after_prev'));
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prevOfRef, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeElementEdge($context, $prevOfRef, VmDom::PROP_NEXT_ELEMENT_SIBLING, $newJit);
        $context->builder->branch($bbAfterPrev);

        $context->builder->positionAtEnd($bbAfterPrev);
        self::storeElementEdge($context, $newChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::objectOrNullVar($context, $prevOfRef));
        self::storeElementEdge($context, $newChild, VmDom::PROP_NEXT_ELEMENT_SIBLING, new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $refChild
        ));
        self::storeElementEdge($context, $refChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, $newJit);
        $context->builder->branch($bbAfterRef);

        $context->builder->positionAtEnd($bbAfterRef);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * After replaceChild: if old was an element, retarget FEC/LEC / sibling links onto new
     * (or unlink when new is non-element).
     */
    public static function afterReplace(Context $context, Value $parent, Value $newChild, Value $oldChild): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_pn_rc'));
        self::ensureProps($context);
        $bbSkip = BasicBlockHelper::append($context, self::tag('dom_pn_rc_skip'));
        $bbDo = BasicBlockHelper::append($context, self::tag('dom_pn_rc_do'));
        $bbDone = BasicBlockHelper::append($context, self::tag('dom_pn_rc_done'));
        $oldIsEl = self::isElementObject($context, $oldChild);
        $newIsEl = self::isElementObject($context, $newChild);
        $either = $context->builder->or($oldIsEl, $newIsEl);
        $context->builder->branchIf($either, $bbDo, $bbSkip);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDo);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $fec = JitDomParentChildLinkLayout::loadFirstElementChild($context, $parent, self::tag('dom_pn_rc_fec'));
        $lec = self::loadLastElementChild($context, $parent, self::tag('dom_pn_rc_lec'));
        $nextEl = self::loadElementEdge($context, $oldChild, VmDom::PROP_NEXT_ELEMENT_SIBLING, self::tag('dom_pn_rc_next'));
        $prevEl = self::loadElementEdge($context, $oldChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::tag('dom_pn_rc_prev'));

        // Both elements: swap into place, count unchanged.
        $bbBoth = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both'));
        $bbNotBoth = BasicBlockHelper::append($context, self::tag('dom_pn_rc_not_both'));
        $both = $context->builder->and($oldIsEl, $newIsEl);
        $context->builder->branchIf($both, $bbBoth, $bbNotBoth);

        $context->builder->positionAtEnd($bbBoth);
        $bbFec = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_fec'));
        $bbAfterFec = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_after_fec'));
        $fecIsOld = $context->builder->icmp(Builder::INT_EQ, $fec, $oldChild);
        $context->builder->branchIf($fecIsOld, $bbFec, $bbAfterFec);
        $context->builder->positionAtEnd($bbFec);
        JitDomParentChildLinkLayout::storeFirstElementChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterFec);

        $context->builder->positionAtEnd($bbAfterFec);
        $bbLec = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_lec'));
        $bbAfterLec = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_after_lec'));
        $lecIsOld = $context->builder->icmp(Builder::INT_EQ, $lec, $oldChild);
        $context->builder->branchIf($lecIsOld, $bbLec, $bbAfterLec);
        $context->builder->positionAtEnd($bbLec);
        self::storeLastElementChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterLec);

        $context->builder->positionAtEnd($bbAfterLec);
        self::storeElementEdge($context, $newChild, VmDom::PROP_NEXT_ELEMENT_SIBLING, self::objectOrNullVar($context, $nextEl));
        self::storeElementEdge($context, $newChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, self::objectOrNullVar($context, $prevEl));
        $bbPrev = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_prev'));
        $bbAfterPrev = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_after_prev'));
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prevEl, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrev);
        $context->builder->positionAtEnd($bbPrev);
        self::storeElementEdge($context, $prevEl, VmDom::PROP_NEXT_ELEMENT_SIBLING, $newJit);
        $context->builder->branch($bbAfterPrev);
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNext = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_next'));
        $bbAfterNext = BasicBlockHelper::append($context, self::tag('dom_pn_rc_both_after_next'));
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextEl, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNext);
        $context->builder->positionAtEnd($bbNext);
        self::storeElementEdge($context, $nextEl, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, $newJit);
        $context->builder->branch($bbAfterNext);
        $context->builder->positionAtEnd($bbAfterNext);
        self::storeElementEdge($context, $oldChild, VmDom::PROP_NEXT_ELEMENT_SIBLING, $nullBox);
        self::storeElementEdge($context, $oldChild, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, $nullBox);
        $context->builder->branch($bbDone);

        // Element → non-element: same as remove. Non-element → element: rare in our repros;
        // treat as insert at old's place using next/prev of... we don't have element links on
        // non-elements. Fall back to: if FEC/LEC still point at nothing useful, leave count+1
        // and set FEC/LEC when both were null.
        $context->builder->positionAtEnd($bbNotBoth);
        $bbOldOnly = BasicBlockHelper::append($context, self::tag('dom_pn_rc_old_only'));
        $bbNewOnly = BasicBlockHelper::append($context, self::tag('dom_pn_rc_new_only'));
        $context->builder->branchIf($oldIsEl, $bbOldOnly, $bbNewOnly);

        $context->builder->positionAtEnd($bbOldOnly);
        // Re-enter remove-style unlink for old element (new is not element).
        self::afterRemove($context, $parent, $oldChild);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNewOnly);
        self::adjustChildElementCount($context, $parent, 1);
        $bbSetFecNew = BasicBlockHelper::append($context, self::tag('dom_pn_rc_new_fec'));
        $bbAfterFecNew = BasicBlockHelper::append($context, self::tag('dom_pn_rc_new_after_fec'));
        $fecNull = $context->builder->icmp(Builder::INT_EQ, $fec, $objPtrTy->constNull());
        $context->builder->branchIf($fecNull, $bbSetFecNew, $bbAfterFecNew);
        $context->builder->positionAtEnd($bbSetFecNew);
        JitDomParentChildLinkLayout::storeFirstElementChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterFecNew);
        $context->builder->positionAtEnd($bbAfterFecNew);
        $bbSetLecNew = BasicBlockHelper::append($context, self::tag('dom_pn_rc_new_lec'));
        $bbAfterLecNew = BasicBlockHelper::append($context, self::tag('dom_pn_rc_new_after_lec'));
        $lecNull = $context->builder->icmp(Builder::INT_EQ, $lec, $objPtrTy->constNull());
        $context->builder->branchIf($lecNull, $bbSetLecNew, $bbAfterLecNew);
        $context->builder->positionAtEnd($bbSetLecNew);
        self::storeLastElementChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterLecNew);
        $context->builder->positionAtEnd($bbAfterLecNew);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    public static function isElementObject(Context $context, Value $obj): Value
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $classId = $context->builder->load($context->builder->structGep($obj, $map['class_id']));
        $isEl = $i1->constInt(0, false);
        foreach (['DOMElement', 'Dom\\Element'] as $className) {
            try {
                $expected = $objectType->lookup($className);
            } catch (\Throwable $e) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expected, false)
            );
            $isEl = $context->builder->or($isEl, $match);
        }

        return $isEl;
    }

    private static function loadLastElementChild(Context $context, Value $parent, string $labelPrefix): Value
    {
        return self::loadParentElementNav($context, $parent, VmDom::PROP_LAST_ELEMENT_CHILD, $labelPrefix);
    }

    private static function storeLastElementChild(Context $context, Value $parent, JITVariable $value): void
    {
        self::ensureProps($context);
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, self::tag('dom_pn_store_lec'));
        $bbDoc = BasicBlockHelper::append($context, self::tag('dom_pn_store_lec_doc'));
        $bbEl = BasicBlockHelper::append($context, self::tag('dom_pn_store_lec_el'));
        $merge = BasicBlockHelper::append($context, self::tag('dom_pn_store_lec_done'));
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);
        $objectType = $context->type->object;
        $context->builder->positionAtEnd($bbDoc);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMDocument', VmDom::PROP_LAST_ELEMENT_CHILD),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($bbEl);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_LAST_ELEMENT_CHILD),
            $value,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
    }

    private static function loadParentElementNav(
        Context $context,
        Value $parent,
        string $prop,
        string $labelPrefix
    ): Value {
        self::ensureProps($context);
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, $labelPrefix);
        $bbDoc = BasicBlockHelper::append($context, $labelPrefix.'_doc');
        $bbEl = BasicBlockHelper::append($context, $labelPrefix.'_el');
        $bbDocDone = BasicBlockHelper::append($context, $labelPrefix.'_doc_done');
        $bbElDone = BasicBlockHelper::append($context, $labelPrefix.'_el_done');
        $merge = BasicBlockHelper::append($context, $labelPrefix.'_merge');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $context->builder->positionAtEnd($bbDoc);
        $docVal = self::loadLinkFlat($context, $parent, 'DOMDocument', $prop, $labelPrefix.'_rd');
        $context->builder->branch($bbDocDone);
        $context->builder->positionAtEnd($bbEl);
        $elVal = self::loadLinkFlat($context, $parent, 'DOMElement', $prop, $labelPrefix.'_re');
        $context->builder->branch($bbElDone);
        $context->builder->positionAtEnd($bbDocDone);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($bbElDone);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($docVal, $bbDocDone);
        $phi->addIncoming($elVal, $bbElDone);

        return $phi;
    }

    private static function loadElementEdge(
        Context $context,
        Value $obj,
        string $prop,
        string $labelPrefix
    ): Value {
        self::ensureProps($context);

        return self::loadLinkFlat($context, $obj, 'DOMElement', $prop, $labelPrefix);
    }

    private static function storeElementEdge(
        Context $context,
        Value $obj,
        string $prop,
        JITVariable $value
    ): void {
        self::ensureProps($context);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'DOMElement', $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadLinkFlat(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        string $label
    ): Value {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $slot = $objectType->propertySlotFor($obj, $className, $prop);
        $slotPtr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $bbNull = BasicBlockHelper::append($context, $label.'_null');
        $bbLoad = BasicBlockHelper::append($context, $label.'_load');
        $merge = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($slotNull, $bbNull, $bbLoad);
        $context->builder->positionAtEnd($bbNull);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($bbLoad);
        $loaded = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($slotPtr, $context->getTypeFromString('__value__*'))
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $bbNull);
        $phi->addIncoming($loaded, $bbLoad);

        return $phi;
    }

    private static function adjustChildElementCount(Context $context, Value $parent, int $delta): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_pn_cnt'));
        self::ensureProps($context);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $i64 = $context->getTypeFromString('int64');
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, self::tag('dom_pn_cnt_cls'));
        $bbDoc = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_doc'));
        $bbEl = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_el'));
        $merge = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_merge'));
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $context->builder->positionAtEnd($bbDoc);
        $docClassId = $objectType->lookup('DOMDocument');
        $docVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $parent,
            'DOMDocument',
            VmDom::PROP_CHILD_ELEMENT_COUNT,
            $docClassId
        );
        $docCur = $context->helper->loadValue($docVar);
        $docNext = $delta >= 0
            ? $context->builder->add($docCur, $i64->constInt($delta, false))
            : $context->builder->sub($docCur, $i64->constInt(-$delta, false));
        $docJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $docNext);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMDocument', VmDom::PROP_CHILD_ELEMENT_COUNT),
            $docJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($bbEl);
        $elVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $parent,
            'DOMElement',
            VmDom::PROP_CHILD_ELEMENT_COUNT,
            $elementClassId
        );
        $elCur = $context->helper->loadValue($elVar);
        $elNext = $delta >= 0
            ? $context->builder->add($elCur, $i64->constInt($delta, false))
            : $context->builder->sub($elCur, $i64->constInt(-$delta, false));
        // Clamp at 0 on decrement.
        if ($delta < 0) {
            $zero = $i64->constInt(0, false);
            $neg = $context->builder->icmp(Builder::INT_SLT, $elNext, $zero);
            $bbClamp = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_clamp'));
            $bbOk = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_ok'));
            $bbAfter = BasicBlockHelper::append($context, self::tag('dom_pn_cnt_after'));
            $context->builder->branchIf($neg, $bbClamp, $bbOk);
            $context->builder->positionAtEnd($bbClamp);
            $clampPred = $context->builder->getInsertBlock();
            $context->builder->branch($bbAfter);
            $context->builder->positionAtEnd($bbOk);
            $okPred = $context->builder->getInsertBlock();
            $context->builder->branch($bbAfter);
            $context->builder->positionAtEnd($bbAfter);
            $phi = $context->builder->phi($i64);
            $phi->addIncoming($zero, $clampPred);
            $phi->addIncoming($elNext, $okPred);
            $elNext = $phi;
        }
        $elJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $elNext);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_CHILD_ELEMENT_COUNT),
            $elJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function nullValueVar(Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }

    private static function objectOrNullVar(Context $context, Value $obj): JITVariable
    {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $obj, $objPtrTy->constNull());
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $bbNull = BasicBlockHelper::append($context, self::tag('dom_pn_box_null'));
        $bbObj = BasicBlockHelper::append($context, self::tag('dom_pn_box_obj'));
        $bbMerge = BasicBlockHelper::append($context, self::tag('dom_pn_box_merge'));
        $context->builder->branchIf($isNull, $bbNull, $bbObj);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbObj);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }
}
