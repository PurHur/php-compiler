<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT LLVM slot sync for DOMNode::replaceChild() (#28671, #32784).
 *
 * Peer {@see JitDomAppendChildLiveSlots} / {@see JitDomRemoveChildLiveSlots}:
 * splice newChild into oldChild's sibling chain; update first/last only when
 * old was an edge. Refresh the existing childNodes list **in place** so held
 * `$list = $parent->childNodes` observes new pins (php-src nodelist.c). Prior
 * path always allocated a fresh list (and collapsed unknown counts to 1/2),
 * leaving held lists with stale `__phpcItem*` — item(1) fell back to lastChild
 * and item(2+) aborted (#32784).
 * DocumentFragment stand-ins expand in place via
 * {@see JitDomAppendChildLiveSlots::expandFragmentChildrenReplace} (#33322).
 * Cross-parent reparent must unlink the old parent first (php-src
 * dom_node_replace_child) — peer appendChild #33404 / #33450.
 * Attr newChild: Hierarchy Request before sibling slots (#33587).
 * Identity replace (new==old): php-src no-op — must not null parent/sibling (#34709 / re-#22678).
 * Same-parent move: unlink newChild before splice; length −1 (#34806 / peer #34803).
 *
 * Reference: php-src ext/dom/node.c dom_node_replace_child.
 */
final class JitDomReplaceChildLiveSlots
{
    /**
     * @param int|null $childCount Known post-replace childNodes length (replace keeps
     *                             count for non-fragment); null → keep existing length.
     */
    public static function sync(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $oldChild,
        ?int $childCount = null
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_live_slots');
        self::ensureLayout($context);

        $bbEnd = BasicBlockHelper::append($context, 'dom_rc_end');
        // php-src dom_node_replace_child — replacing a node with itself is a no-op (#34709).
        // Without this, syncNonFragment nulls parent/sibling on the same pointer.
        $bbSame = BasicBlockHelper::append($context, 'dom_rc_identity_nop');
        $bbNotSame = BasicBlockHelper::append($context, 'dom_rc_not_identity');
        $isSame = $context->builder->icmp(Builder::INT_EQ, $newChild, $oldChild);
        $context->builder->branchIf($isSame, $bbSame, $bbNotSame);
        $context->builder->positionAtEnd($bbSame);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbNotSame);
        // php-src: Attr is not content — Hierarchy Request before sibling splice (#33587).
        $bbAttr = BasicBlockHelper::append($context, 'dom_rc_ls_attr');
        $bbNotAttr = BasicBlockHelper::append($context, 'dom_rc_ls_not_attr');
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $newChild);
        $context->builder->branchIf($isAttr, $bbAttr, $bbNotAttr);

        $context->builder->positionAtEnd($bbAttr);
        \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Hierarchy Request Error',
            null,
            '',
            0,
            DomExceptionConstants::HIERARCHY_REQUEST_ERR
        );

        $context->builder->positionAtEnd($bbNotAttr);
        $bbFrag = BasicBlockHelper::append($context, 'dom_rc_frag');
        $bbNormal = BasicBlockHelper::append($context, 'dom_rc_normal');
        $isFrag = JitDomAppendChildLiveSlots::isDocumentFragmentNode($context, $newChild);
        $context->builder->branchIf($isFrag, $bbFrag, $bbNormal);

        $context->builder->positionAtEnd($bbFrag);
        JitDomAppendChildLiveSlots::expandFragmentChildrenReplace(
            $context,
            $parent,
            $newChild,
            $oldChild
        );
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbNormal);
        self::syncNonFragment($context, $parent, $newChild, $oldChild, $childCount);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbEnd);
    }

    /**
     * Element/text replaceChild (non-fragment) (#28671 / #33322).
     *
     * @param int|null $childCount
     */
    public static function syncNonFragment(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $oldChild,
        ?int $childCount = null
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_nonfrag');
        self::ensureLayout($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $i1 = $context->getTypeFromString('int1');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $nullBox = self::nullValueVar($context);

        // php-src: if newChild already has a different parent, remove it first (#33450).
        JitDomAppendChildLiveSlots::detachFromForeignParentIfNeeded($context, $parent, $newChild);

        // Same-parent membership (peer insertBefore #34803 / appendChild #27476).
        $curFirst0 = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_chk_first');
        $curLast0 = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_rc_chk_last');
        $isFirstChild = $context->builder->icmp(Builder::INT_EQ, $curFirst0, $newChild);
        $isLastChild = $context->builder->icmp(Builder::INT_EQ, $curLast0, $newChild);
        $chkPrev = self::loadSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, 'dom_rc_chk_prev');
        $chkNext = self::loadSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, 'dom_rc_chk_next');
        $hasPrev = $context->builder->icmp(Builder::INT_NE, $chkPrev, $objPtrTy->constNull());
        $hasNext = $context->builder->icmp(Builder::INT_NE, $chkNext, $objPtrTy->constNull());
        $isLinked = $context->builder->or($hasPrev, $hasNext);
        $curParent = self::loadSibling($context, $newChild, VmDom::PROP_PARENT_NODE, 'dom_rc_chk_parent');
        $parentMatches = $context->builder->icmp(Builder::INT_EQ, $curParent, $parent);
        $linkedHere = $context->builder->and($isLinked, $parentMatches);
        $isMember = $context->builder->or($context->builder->or($isFirstChild, $isLastChild), $linkedHere);

        $wasMoveAlloca = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($isMember, $wasMoveAlloca);

        $bbUnlink = BasicBlockHelper::append($context, 'dom_rc_unlink');
        $bbAfterUnlink = BasicBlockHelper::append($context, 'dom_rc_after_unlink');
        $context->builder->branchIf($isMember, $bbUnlink, $bbAfterUnlink);

        // Unlink newChild from this parent before splicing into oldChild's slot (#34806).
        $context->builder->positionAtEnd($bbUnlink);
        $oldPrev = self::loadSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, 'dom_rc_un_prev');
        $oldNext = self::loadSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, 'dom_rc_un_next');
        $firstU = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_un_first');
        $lastU = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_rc_un_last');

        $bbUnPrevLink = BasicBlockHelper::append($context, 'dom_rc_un_prev_link');
        $bbUnAfterPrev = BasicBlockHelper::append($context, 'dom_rc_un_after_prev');
        $oldPrevNull = $context->builder->icmp(Builder::INT_EQ, $oldPrev, $objPtrTy->constNull());
        $context->builder->branchIf($oldPrevNull, $bbUnAfterPrev, $bbUnPrevLink);
        $context->builder->positionAtEnd($bbUnPrevLink);
        self::storeSibling($context, $oldPrev, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $oldNext));
        $context->builder->branch($bbUnAfterPrev);

        $context->builder->positionAtEnd($bbUnAfterPrev);
        $bbUnNextLink = BasicBlockHelper::append($context, 'dom_rc_un_next_link');
        $bbUnAfterNext = BasicBlockHelper::append($context, 'dom_rc_un_after_next');
        $oldNextNull = $context->builder->icmp(Builder::INT_EQ, $oldNext, $objPtrTy->constNull());
        $context->builder->branchIf($oldNextNull, $bbUnAfterNext, $bbUnNextLink);
        $context->builder->positionAtEnd($bbUnNextLink);
        self::storeSibling($context, $oldNext, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $oldPrev));
        $context->builder->branch($bbUnAfterNext);

        $context->builder->positionAtEnd($bbUnAfterNext);
        $firstIsNew = $context->builder->icmp(Builder::INT_EQ, $firstU, $newChild);
        $bbUnSetFirst = BasicBlockHelper::append($context, 'dom_rc_un_set_first');
        $bbUnAfterFirst = BasicBlockHelper::append($context, 'dom_rc_un_after_first');
        $context->builder->branchIf($firstIsNew, $bbUnSetFirst, $bbUnAfterFirst);
        $context->builder->positionAtEnd($bbUnSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, self::objectOrNullVar($context, $oldNext));
        $context->builder->branch($bbUnAfterFirst);

        $context->builder->positionAtEnd($bbUnAfterFirst);
        $lastIsNew = $context->builder->icmp(Builder::INT_EQ, $lastU, $newChild);
        $bbUnSetLast = BasicBlockHelper::append($context, 'dom_rc_un_set_last');
        $bbUnAfterLast = BasicBlockHelper::append($context, 'dom_rc_un_after_last');
        $context->builder->branchIf($lastIsNew, $bbUnSetLast, $bbUnAfterLast);
        $context->builder->positionAtEnd($bbUnSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, self::objectOrNullVar($context, $oldPrev));
        $context->builder->branch($bbUnAfterLast);

        $context->builder->positionAtEnd($bbUnAfterLast);
        self::storeSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, $nullBox);
        $context->builder->branch($bbAfterUnlink);

        $context->builder->positionAtEnd($bbAfterUnlink);
        // Re-read oldChild edges after possible unlink (new may have been old's neighbor).

        $prev = self::loadSibling($context, $oldChild, VmDom::PROP_PREVIOUS_SIBLING, 'dom_rc_prev');
        $next = self::loadSibling($context, $oldChild, VmDom::PROP_NEXT_SIBLING, 'dom_rc_next');
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_first');
        $last = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_rc_last');

        // prev.next = newChild (when prev non-null)
        $bbPrevLink = BasicBlockHelper::append($context, 'dom_rc_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_rc_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, $newJit);
        $context->builder->branch($bbAfterPrev);

        // next.prev = newChild (when next non-null)
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, 'dom_rc_next_link');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_rc_after_next');
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeSibling($context, $next, VmDom::PROP_PREVIOUS_SIBLING, $newJit);
        $context->builder->branch($bbAfterNext);

        // firstChild ← newChild when replacing the first
        $context->builder->positionAtEnd($bbAfterNext);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_rc_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_rc_after_first');
        $firstIsOld = $context->builder->icmp(Builder::INT_EQ, $first, $oldChild);
        $context->builder->branchIf($firstIsOld, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $newJit);
        $context->builder->branch($bbAfterFirst);

        // lastChild ← newChild when replacing the last
        $context->builder->positionAtEnd($bbAfterFirst);
        $bbSetLast = BasicBlockHelper::append($context, 'dom_rc_set_last');
        $bbAfterLast = BasicBlockHelper::append($context, 'dom_rc_after_last');
        $lastIsOld = $context->builder->icmp(Builder::INT_EQ, $last, $oldChild);
        $context->builder->branchIf($lastIsOld, $bbSetLast, $bbAfterLast);
        $context->builder->positionAtEnd($bbSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $newJit);
        $context->builder->branch($bbAfterLast);

        $context->builder->positionAtEnd($bbAfterLast);
        self::storeSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $prev));
        self::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $next));
        self::storeParentNode($context, $newChild, $parent);

        // Detach old — null parent/sibling on DOMElement layout (#28672 / #27411).
        self::storeSibling($context, $oldChild, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $oldChild, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::storeParentNodeNull($context, $oldChild);

        $newFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_nfirst');
        // Only-child replace: first==newChild and nextSibling null — do not loadSibling(null).
        $bbSecondNull = BasicBlockHelper::append($context, 'dom_rc_second_null');
        $bbSecondRead = BasicBlockHelper::append($context, 'dom_rc_second_read');
        $bbSecondMerge = BasicBlockHelper::append($context, 'dom_rc_second_merge');
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $context->builder->branchIf($firstNull, $bbSecondNull, $bbSecondRead);
        $context->builder->positionAtEnd($bbSecondNull);
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondRead);
        $loadedSecond = self::loadSibling($context, $newFirst, VmDom::PROP_NEXT_SIBLING, 'dom_rc_item1');
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondMerge);
        $item1 = $context->builder->phi($objPtrTy);
        $item1->addIncoming($objPtrTy->constNull(), $nullPred);
        $item1->addIncoming($loadedSecond, $readPred);

        // Same-parent move: length −1 (removed old + already-counted new). Fresh replace keeps length.
        $wasMove = $context->builder->load($wasMoveAlloca);
        $bbMoveLen = BasicBlockHelper::append($context, 'dom_rc_move_len');
        $bbFreshLen = BasicBlockHelper::append($context, 'dom_rc_fresh_len');
        $bbAfterLen = BasicBlockHelper::append($context, 'dom_rc_after_len');
        $context->builder->branchIf($wasMove, $bbMoveLen, $bbFreshLen);

        $context->builder->positionAtEnd($bbMoveLen);
        self::decrementChildNodesLengthInPlace($context, $parent);
        self::refreshChildNodesListInPlace($context, $parent, null, $newFirst, $item1);
        $context->builder->branch($bbAfterLen);

        $context->builder->positionAtEnd($bbFreshLen);
        self::refreshChildNodesListInPlace($context, $parent, $childCount, $newFirst, $item1);
        $context->builder->branch($bbAfterLen);

        $context->builder->positionAtEnd($bbAfterLen);
        // saveXML reads PROP_USER_SCRIPT_INNER_XML — rebuild after text/element splice
        // so replaceChild(createTextNode) matches Zend (#33335). Walk ancestors so
        // saveXML($root) after nested createElement replace matches Zend (#33450).
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parent);
    }

    /**
     * Same-parent replaceChild move: held childNodes length −1 (#34806).
     */
    private static function decrementChildNodesLengthInPlace(Context $context, Value $owner): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_dec_len');
        self::ensureLayout($context);
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbDone = BasicBlockHelper::append($context, 'dom_rc_dec_done');
        $bbDec = BasicBlockHelper::append($context, 'dom_rc_dec_do');
        $context->builder->branchIf($missing, $bbDone, $bbDec);
        $context->builder->positionAtEnd($bbDec);
        $lengthVar = \PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $existing,
            'DOMNodeList',
            'length',
            $objectType->lookup('DOMNodeList')
        );
        $current = $context->helper->loadValue($lengthVar);
        $one = $i64->constInt(1, false);
        $gt = $context->builder->icmp(Builder::INT_UGT, $current, $one);
        $bbSub = BasicBlockHelper::append($context, 'dom_rc_dec_sub');
        $context->builder->branchIf($gt, $bbSub, $bbDone);
        $context->builder->positionAtEnd($bbSub);
        $next = $context->builder->sub($current, $one);
        $nextJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $next);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $nextJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    public static function refreshHeldChildNodes(
        Context $context,
        Value $owner,
        int $childCount,
        Value $newFirst,
        Value $newSecond
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rch_held');
        self::ensureLayout($context);
        self::refreshChildNodesListInPlace($context, $owner, $childCount, $newFirst, $newSecond);
    }

    /**
     * Refresh pins / length on the existing childNodes list without replacing the
     * list object — held `$list` must observe the splice (#32784 / #32774 peer).
     *
     * @param int|null $childCount null → keep existing length (element replace)
     */
    private static function refreshChildNodesListInPlace(
        Context $context,
        Value $owner,
        ?int $childCount,
        Value $newFirst,
        Value $newSecond
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_refresh_list');
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $nullBox = self::nullValueVar($context);

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_rc_list_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_rc_list_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_rc_list_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_rc_seed_empty');
        $bbSome = BasicBlockHelper::append($context, 'dom_rc_seed_some');
        $context->builder->branchIf($firstNull, $bbEmpty, $bbSome);
        $context->builder->positionAtEnd($bbEmpty);
        self::writeChildNodesList($context, $owner, 0, null, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSome);
        $seedLen = (null !== $childCount && $childCount >= 1) ? $childCount : null;
        $secondNull = $context->builder->icmp(Builder::INT_EQ, $newSecond, $objPtrTy->constNull());
        $bbOne = BasicBlockHelper::append($context, 'dom_rc_seed_one');
        $bbTwo = BasicBlockHelper::append($context, 'dom_rc_seed_two');
        $context->builder->branchIf($secondNull, $bbOne, $bbTwo);
        $context->builder->positionAtEnd($bbOne);
        self::writeChildNodesList($context, $owner, $seedLen ?? 1, $newFirst, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbTwo);
        self::writeChildNodesList(
            $context,
            $owner,
            $seedLen ?? 2,
            $newFirst,
            $newSecond
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBump);
        if (null !== $childCount && $childCount >= 0) {
            $lenJit = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($childCount, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
                $lenJit,
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );

        $firstNull2 = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $bbClearPins = BasicBlockHelper::append($context, 'dom_rc_clear_pins');
        $bbSetPins = BasicBlockHelper::append($context, 'dom_rc_set_pins');
        $bbPinsDone = BasicBlockHelper::append($context, 'dom_rc_pins_done');
        $context->builder->branchIf($firstNull2, $bbClearPins, $bbSetPins);

        $context->builder->positionAtEnd($bbClearPins);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);

        $context->builder->positionAtEnd($bbSetPins);
        $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newFirst);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $i0,
            JITVariable::TYPE_VALUE
        );
        $secondNull2 = $context->builder->icmp(Builder::INT_EQ, $newSecond, $objPtrTy->constNull());
        $bbPin1Null = BasicBlockHelper::append($context, 'dom_rc_pin1_null');
        $bbPin1Set = BasicBlockHelper::append($context, 'dom_rc_pin1_set');
        $context->builder->branchIf($secondNull2, $bbPin1Null, $bbPin1Set);
        $context->builder->positionAtEnd($bbPin1Null);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);
        $context->builder->positionAtEnd($bbPin1Set);
        $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newSecond);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $i1,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);

        $context->builder->positionAtEnd($bbPinsDone);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function ensureLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
        ] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
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
    }

    private static function loadChildNodesListObject(Context $context, Value $owner): Value
    {
        return self::loadLink($context, $owner, 'DOMElement', VmDom::PROP_CHILD_NODES, 'dom_rc_cn');
    }

    private static function storeChildEdge(
        Context $context,
        Value $parent,
        string $prop,
        JITVariable $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($parent, 'DOMElement', $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadChildEdge(
        Context $context,
        Value $obj,
        string $prop,
        string $label
    ): Value {
        return self::loadLink($context, $obj, 'DOMElement', $prop, $label);
    }

    private static function storeSibling(
        Context $context,
        Value $obj,
        string $prop,
        JITVariable $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'DOMElement', $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeParentNode(Context $context, Value $child, Value $parent): void
    {
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeParentNodeNull(Context $context, Value $child): void
    {
        self::storeSibling($context, $child, VmDom::PROP_PARENT_NODE, self::nullValueVar($context));
    }

    private static function writeChildNodesList(
        Context $context,
        Value $owner,
        int $length,
        ?Value $item0,
        ?Value $item1
    ): void {
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthJit,
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
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadSibling(
        Context $context,
        Value $obj,
        string $prop,
        string $label
    ): Value {
        return self::loadLink($context, $obj, 'DOMElement', $prop, $label);
    }

    private static function loadLink(
        Context $context,
        Value $obj,
        string $class,
        string $prop,
        string $label
    ): Value {
        $objectType = $context->type->object;
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slot = $objectType->propertySlotFor($obj, $class, $prop);
        $ptr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $voidPtr->constNull());
        $bbNull = BasicBlockHelper::append($context, $label.'_null');
        $bbRead = BasicBlockHelper::append($context, $label.'_read');
        $bbMerge = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($slotNull, $bbNull, $bbRead);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbRead);
        $loaded = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($ptr, $context->getTypeFromString('__value__*'))
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $bbNull);
        $phi->addIncoming($loaded, $readPred);

        return $phi;
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
        $bbNull = BasicBlockHelper::append($context, 'dom_rc_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_rc_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_rc_box_merge');
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
