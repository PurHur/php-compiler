<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT LLVM slot sync for Element DOMNode::appendChild() (#27476, #32929, #33312).
 *
 * Peer {@see JitDomInsertBefore}: skip NestedJIT for createElement nodes.
 * Parent firstChild/lastChild use DOMElement (createElement layout). DOMNode
 * first/last on an Element allocation aliases tagName/nodeName (#32361 / #24973).
 * Sibling/parentNode on children use DOMElement (DOMNode sibling aliases parentNode).
 * Move detection uses first/last identity **or** non-null prev/next siblings —
 * parentNode slots are unreliable after lastChild stores on thin AOT (#27476).
 * Middle-child moves that only checked first/last took the fresh-append path and
 * SIGSEGV'd when walking childNodes (#32929).
 * DocumentFragment stand-ins (`nodeName` `#document-fragment`) expand children
 * onto the parent (php-src fragment move); linking the fragment itself left
 * `#document-fragment` in childNodes and SIGSEGV'd on item(N) (#33312).
 *
 * Reference: php-src ext/dom/node.c dom_node_append_child.
 * Peer: {@see JitDomInsertBefore}.
 */
final class JitDomAppendChildLiveSlots
{
    public static function sync(Context $context, Value $parent, Value $child): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_live_slots');
        self::ensureLayout($context);

        // php-src: DocumentFragment children move onto the parent; fragment stays empty (#33312).
        $bbFrag = BasicBlockHelper::append($context, 'dom_acls_frag');
        $bbNormal = BasicBlockHelper::append($context, 'dom_acls_normal');
        $bbSyncEnd = BasicBlockHelper::append($context, 'dom_acls_sync_end');
        $isFrag = self::isDocumentFragmentNode($context, $child);
        $context->builder->branchIf($isFrag, $bbFrag, $bbNormal);

        $context->builder->positionAtEnd($bbFrag);
        self::expandFragmentChildrenAppend($context, $parent, $child);
        $context->builder->branch($bbSyncEnd);

        $context->builder->positionAtEnd($bbNormal);
        self::syncNonFragment($context, $parent, $child);
        $context->builder->branch($bbSyncEnd);

        $context->builder->positionAtEnd($bbSyncEnd);
    }

    /**
     * Append one non-fragment child (php-src element/text path) (#27476 / #33312).
     *
     * Fragment expand must call this — not {@see sync} — so codegen does not
     * re-enter expand via the dead fragment IR arm.
     */
    public static function syncNonFragment(Context $context, Value $parent, Value $child): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_nonfrag');
        self::ensureLayout($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $childJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $child);
        $nullBox = self::nullValueVar($context);

        // Detect same-parent membership without parentNode (#27476 / #32929):
        // first/last identity OR already linked via prev/next (middle children).
        $curFirst0 = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_chk_first');
        $curLast0 = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_chk_last');
        $isFirstChild = $context->builder->icmp(Builder::INT_EQ, $curFirst0, $child);
        $isLastChild = $context->builder->icmp(Builder::INT_EQ, $curLast0, $child);
        $chkPrev = self::loadSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, 'dom_acls_chk_prev');
        $chkNext = self::loadSibling($context, $child, VmDom::PROP_NEXT_SIBLING, 'dom_acls_chk_next');
        $hasPrev = $context->builder->icmp(Builder::INT_NE, $chkPrev, $objPtrTy->constNull());
        $hasNext = $context->builder->icmp(Builder::INT_NE, $chkNext, $objPtrTy->constNull());
        $isLinked = $context->builder->or($hasPrev, $hasNext);
        $isMember = $context->builder->or($context->builder->or($isFirstChild, $isLastChild), $isLinked);

        $bbDone = BasicBlockHelper::append($context, 'dom_acls_done');
        $bbAlreadyLast = BasicBlockHelper::append($context, 'dom_acls_already_last');
        $bbNotLast = BasicBlockHelper::append($context, 'dom_acls_not_last');
        // php-src: appendChild of already-last child is a no-op (still returns child).
        $context->builder->branchIf($isLastChild, $bbAlreadyLast, $bbNotLast);

        $context->builder->positionAtEnd($bbAlreadyLast);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNotLast);
        $bbMove = BasicBlockHelper::append($context, 'dom_acls_move');
        $bbFresh = BasicBlockHelper::append($context, 'dom_acls_fresh');
        $context->builder->branchIf($isMember, $bbMove, $bbFresh);

        // ---- Same-parent move ----
        $context->builder->positionAtEnd($bbMove);
        $prev = self::loadSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, 'dom_acls_prev');
        $next = self::loadSibling($context, $child, VmDom::PROP_NEXT_SIBLING, 'dom_acls_next');
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_mfirst');
        $last = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_mlast');

        $bbPrevLink = BasicBlockHelper::append($context, 'dom_acls_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_acls_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterPrev);

        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, 'dom_acls_next_link');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_acls_after_next');
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeSibling($context, $next, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterNext);

        $context->builder->positionAtEnd($bbAfterNext);
        $firstIsChild = $context->builder->icmp(Builder::INT_EQ, $first, $child);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_acls_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_acls_after_first');
        $context->builder->branchIf($firstIsChild, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterFirst);

        $context->builder->positionAtEnd($bbAfterFirst);
        $lastIsChild = $context->builder->icmp(Builder::INT_EQ, $last, $child);
        $bbSetLast = BasicBlockHelper::append($context, 'dom_acls_set_last');
        $bbAfterLast = BasicBlockHelper::append($context, 'dom_acls_after_last');
        $context->builder->branchIf($lastIsChild, $bbSetLast, $bbAfterLast);
        $context->builder->positionAtEnd($bbSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterLast);

        $context->builder->positionAtEnd($bbAfterLast);
        self::storeSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);

        $tail = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_tail');
        $tailNull = $context->builder->icmp(Builder::INT_EQ, $tail, $objPtrTy->constNull());
        $bbEmptyAfter = BasicBlockHelper::append($context, 'dom_acls_empty_after');
        $bbLinkTail = BasicBlockHelper::append($context, 'dom_acls_link_tail');
        $context->builder->branchIf($tailNull, $bbEmptyAfter, $bbLinkTail);

        $context->builder->positionAtEnd($bbEmptyAfter);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $childJit);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        // In-place bump/seed — writeChildNodesList allocated a fresh list and left
        // held `$list = $parent->childNodes` (length 0) stale → item(0) SIGSEGV (#32834).
        self::incrementChildNodesLengthInPlace(
            $context,
            $parent,
            $child,
            $objPtrTy->constNull()
        );
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLinkTail);
        self::storeSibling($context, $tail, VmDom::PROP_NEXT_SIBLING, $childJit);
        self::storeSibling(
            $context,
            $child,
            VmDom::PROP_PREVIOUS_SIBLING,
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $tail)
        );
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        // Same-parent move keeps length; only refresh pins (do not allocate a fresh
        // length=2 list — that collapsed 3+ children and stale held lists, #32929).
        $newFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_nfirst');
        $pin1 = self::loadSibling($context, $newFirst, VmDom::PROP_NEXT_SIBLING, 'dom_acls_move_pin1');
        self::refreshChildNodesPinsInPlace($context, $parent, $newFirst, $pin1);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        // ---- Fresh append ----
        $context->builder->positionAtEnd($bbFresh);
        $curFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_ffirst');
        $curLast = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_flast');
        $empty = $context->builder->icmp(Builder::INT_EQ, $curFirst, $objPtrTy->constNull());
        $bbFirstChild = BasicBlockHelper::append($context, 'dom_acls_first_child');
        $bbAppendTail = BasicBlockHelper::append($context, 'dom_acls_append_tail');
        $context->builder->branchIf($empty, $bbFirstChild, $bbAppendTail);

        $context->builder->positionAtEnd($bbFirstChild);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $childJit);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        self::storeSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);
        // Peer append-tail #29048 / empty held list #32834: refresh existing NodeList
        // in place (0→1), do not replace the list object.
        self::incrementChildNodesLengthInPlace(
            $context,
            $parent,
            $child,
            $objPtrTy->constNull()
        );
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbAppendTail);
        $tailNull2 = $context->builder->icmp(Builder::INT_EQ, $curLast, $objPtrTy->constNull());
        $useFirstAsTail = BasicBlockHelper::append($context, 'dom_acls_use_first_tail');
        $haveTail = BasicBlockHelper::append($context, 'dom_acls_have_tail');
        $context->builder->branchIf($tailNull2, $useFirstAsTail, $haveTail);
        $context->builder->positionAtEnd($useFirstAsTail);
        $context->builder->branch($haveTail);
        $context->builder->positionAtEnd($haveTail);
        $tailPhi = $context->builder->phi($objPtrTy);
        $tailPhi->addIncoming($curFirst, $useFirstAsTail);
        $tailPhi->addIncoming($curLast, $bbAppendTail);

        self::storeSibling($context, $tailPhi, VmDom::PROP_NEXT_SIBLING, $childJit);
        self::storeSibling(
            $context,
            $child,
            VmDom::PROP_PREVIOUS_SIBLING,
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $tailPhi)
        );
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        // +1 in place — absolute writeChildNodesList(..., 2) left loadXML-seeded
        // held `$list = $parent->childNodes` stale at length 2 (#29048 / re-#28509).
        // Pin __phpcItem1 to first->next (second child), never the new last (#32784).
        $pin1 = self::loadSibling($context, $curFirst, VmDom::PROP_NEXT_SIBLING, 'dom_acls_pin1');
        self::incrementChildNodesLengthInPlace($context, $parent, $curFirst, $pin1);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * Thin-AOT DocumentFragment stand-in — {@see JitDomCreateDocumentFragment} (#33312).
     */
    public static function isDocumentFragmentNode(Context $context, Value $node): Value
    {
        self::ensureLayout($context);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'nodeName')) {
            $objectType->defineProperty($elementClassId, 'nodeName', JITVariable::TYPE_STRING);
        }
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'nodeName',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $fragLit = $context->builder->load($context->constantStringFromString('#document-fragment'));
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $fragLit),
            $i64->constInt(0, false)
        );
    }

    /**
     * Move fragment children onto parent via append (php-src fragment expand) (#33312).
     *
     * Unlink each child before {@see sync} so middle-fragment siblings are not
     * mis-classified as same-parent moves on the destination.
     */
    public static function expandFragmentChildrenAppend(
        Context $context,
        Value $parent,
        Value $fragment,
        bool $syncInnerXml = true
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_frag_expand');
        self::ensureLayout($context);
        if ($syncInnerXml) {
            self::concatFragmentInnerXmlOntoParent($context, $parent, $fragment, false);
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);
        $first = self::loadChildEdge($context, $fragment, VmDom::PROP_FIRST_CHILD, 'dom_acls_frag_first');
        self::storeChildEdge($context, $fragment, VmDom::PROP_FIRST_CHILD, $nullBox);
        self::storeChildEdge($context, $fragment, VmDom::PROP_LAST_CHILD, $nullBox);
        self::zeroChildNodesLengthInPlace($context, $fragment);
        JitDomCreateElement::storeUserScriptInnerXml($context, $fragment, '');

        $curAlloca = $context->builder->alloca($objPtrTy);
        $context->builder->store($first, $curAlloca);
        $bbLoop = BasicBlockHelper::append($context, 'dom_acls_frag_loop');
        $bbBody = BasicBlockHelper::append($context, 'dom_acls_frag_body');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_frag_done');
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $next = self::loadSibling($context, $cur, VmDom::PROP_NEXT_SIBLING, 'dom_acls_frag_next');
        self::storeSibling($context, $cur, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $cur, VmDom::PROP_NEXT_SIBLING, $nullBox);
        // Non-fragment sync only — avoid codegen re-entry into expand (#33312).
        self::syncNonFragment($context, $parent, $cur);
        $context->builder->store($next, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * Move fragment children before $refChild (php-src insertFragmentChildrenBefore) (#33312).
     */
    public static function expandFragmentChildrenInsertBefore(
        Context $context,
        Value $parent,
        Value $fragment,
        Value $refChild,
        bool $syncInnerXml = true
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_frag_expand');
        self::ensureLayout($context);
        if ($syncInnerXml) {
            // Prepend fragment markup when inserting before the current first child.
            $firstBefore = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_ib_frag_pfirst');
            $atStart = $context->builder->icmp(Builder::INT_EQ, $refChild, $firstBefore);
            $bbPrepend = BasicBlockHelper::append($context, 'dom_ib_frag_prepend_xml');
            $bbAppendXml = BasicBlockHelper::append($context, 'dom_ib_frag_append_xml');
            $bbAfterXml = BasicBlockHelper::append($context, 'dom_ib_frag_after_xml');
            $context->builder->branchIf($atStart, $bbPrepend, $bbAppendXml);
            $context->builder->positionAtEnd($bbPrepend);
            self::concatFragmentInnerXmlOntoParent($context, $parent, $fragment, true);
            $context->builder->branch($bbAfterXml);
            $context->builder->positionAtEnd($bbAppendXml);
            self::concatFragmentInnerXmlOntoParent($context, $parent, $fragment, false);
            $context->builder->branch($bbAfterXml);
            $context->builder->positionAtEnd($bbAfterXml);
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);
        $first = self::loadChildEdge($context, $fragment, VmDom::PROP_FIRST_CHILD, 'dom_ib_frag_first');
        self::storeChildEdge($context, $fragment, VmDom::PROP_FIRST_CHILD, $nullBox);
        self::storeChildEdge($context, $fragment, VmDom::PROP_LAST_CHILD, $nullBox);
        self::zeroChildNodesLengthInPlace($context, $fragment);
        JitDomCreateElement::storeUserScriptInnerXml($context, $fragment, '');

        $curAlloca = $context->builder->alloca($objPtrTy);
        $context->builder->store($first, $curAlloca);
        $bbLoop = BasicBlockHelper::append($context, 'dom_ib_frag_loop');
        $bbBody = BasicBlockHelper::append($context, 'dom_ib_frag_body');
        $bbDone = BasicBlockHelper::append($context, 'dom_ib_frag_done');
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $next = self::loadSibling($context, $cur, VmDom::PROP_NEXT_SIBLING, 'dom_ib_frag_next');
        self::storeSibling($context, $cur, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $cur, VmDom::PROP_NEXT_SIBLING, $nullBox);
        // Always insert before the same ref — preserves fragment order (#33312).
        // Non-fragment path only — avoid codegen re-entry into expand.
        JitDomInsertBeforeLiveSlots::syncNonFragment($context, $parent, $cur, $refChild);
        $context->builder->store($next, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * replaceChild(DocumentFragment): unlink $oldChild then expand fragment (#33322).
     *
     * php-src: remove old, insert fragment children before old's former next sibling
     * (or append when old was last). Peer {@see VmDom::replaceChild} fragment arm.
     * INNER_XML is rebuilt from children — append/prepend concat is wrong for middle splices.
     */
    public static function expandFragmentChildrenReplace(
        Context $context,
        Value $parent,
        Value $fragment,
        Value $oldChild
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_frag_expand');
        self::ensureLayout($context);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $next = self::loadSibling($context, $oldChild, VmDom::PROP_NEXT_SIBLING, 'dom_rc_frag_next');
        JitDomRemoveChildLiveSlots::sync($context, $parent, $oldChild);

        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $bbAppend = BasicBlockHelper::append($context, 'dom_rc_frag_append');
        $bbInsert = BasicBlockHelper::append($context, 'dom_rc_frag_insert');
        $bbAfter = BasicBlockHelper::append($context, 'dom_rc_frag_after');
        $context->builder->branchIf($nextNull, $bbAppend, $bbInsert);

        $context->builder->positionAtEnd($bbAppend);
        self::expandFragmentChildrenAppend($context, $parent, $fragment, false);
        $context->builder->branch($bbAfter);

        $context->builder->positionAtEnd($bbInsert);
        self::expandFragmentChildrenInsertBefore($context, $parent, $fragment, $next, false);
        $context->builder->branch($bbAfter);

        $context->builder->positionAtEnd($bbAfter);
        self::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
    }

    /**
     * Rebuild parent INNER_XML from element children's tagName / INNER_XML (#33322).
     *
     * Empty createElement children become {@code <tag/>}; non-empty keep nested markup.
     */
    public static function rebuildUserScriptInnerXmlFromElementChildren(
        Context $context,
        Value $parent
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_rebuild_inner');
        self::ensureLayout($context);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', JITVariable::TYPE_STRING);
        }
        $objPtrTy = $context->getTypeFromString('__object__*');
        $strTy = $context->getTypeFromString('__string__*');
        $accAlloca = $context->builder->alloca($strTy);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $accAlloca);

        $curAlloca = $context->builder->alloca($objPtrTy);
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_rb_first');
        $context->builder->store($first, $curAlloca);
        $bbLoop = BasicBlockHelper::append($context, 'dom_rc_rb_loop');
        $bbBody = BasicBlockHelper::append($context, 'dom_rc_rb_body');
        $bbDone = BasicBlockHelper::append($context, 'dom_rc_rb_done');
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $tagVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $cur,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagStr = $context->helper->loadValue($tagVar);
        $innerVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $cur,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $elementClassId
        );
        $innerStr = $context->helper->loadValue($innerVar);
        $lt = $context->builder->load($context->constantStringFromString('<'));
        $gt = $context->builder->load($context->constantStringFromString('>'));
        $slashGt = $context->builder->load($context->constantStringFromString('/>'));
        $ltSlash = $context->builder->load($context->constantStringFromString('</'));
        $i64 = $context->getTypeFromString('int64');
        $innerEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $innerStr, $empty),
            $i64->constInt(0, false)
        );
        $bbEmptyEl = BasicBlockHelper::append($context, 'dom_rc_rb_empty_el');
        $bbFullEl = BasicBlockHelper::append($context, 'dom_rc_rb_full_el');
        $bbPieceDone = BasicBlockHelper::append($context, 'dom_rc_rb_piece_done');
        $context->builder->branchIf($innerEmpty, $bbEmptyEl, $bbFullEl);

        $context->builder->positionAtEnd($bbEmptyEl);
        $emptyPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $lt, $tagStr),
            $slashGt
        );
        $emptyPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPieceDone);

        $context->builder->positionAtEnd($bbFullEl);
        $open = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $lt, $tagStr),
            $gt
        );
        $close = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ltSlash, $tagStr),
            $gt
        );
        $fullPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $open, $innerStr),
            $close
        );
        $fullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPieceDone);

        $context->builder->positionAtEnd($bbPieceDone);
        $piece = $context->builder->phi($strTy);
        $piece->addIncoming($emptyPiece, $emptyPred);
        $piece->addIncoming($fullPiece, $fullPred);
        $acc = $context->builder->load($accAlloca);
        $merged = JitStringConcat::concat($context, $acc, $piece);
        $context->builder->store($merged, $accAlloca);
        $next = self::loadSibling($context, $cur, VmDom::PROP_NEXT_SIBLING, 'dom_rc_rb_next');
        $context->builder->store($next, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);
        $final = $context->builder->load($accAlloca);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $final
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_USER_SCRIPT_INNER_XML),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    /** Concat fragment INNER_XML onto parent (append or prepend) for AOT saveXML (#33312). */
    private static function concatFragmentInnerXmlOntoParent(
        Context $context,
        Value $parent,
        Value $fragment,
        bool $prepend
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        $fragVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $fragment,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $classId
        );
        $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $parent,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $classId
        );
        $fragStr = $context->helper->loadValue($fragVar);
        $parentStr = $context->helper->loadValue($parentVar);
        $merged = $prepend
            ? JitStringConcat::concat($context, $fragStr, $parentStr)
            : JitStringConcat::concat($context, $parentStr, $fragStr);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $merged
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_USER_SCRIPT_INNER_XML),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    /** Set held childNodes length to 0 without replacing the list object (#33312). */
    private static function zeroChildNodesLengthInPlace(Context $context, Value $owner): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_zero_len');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSkip = BasicBlockHelper::append($context, 'dom_acls_zero_skip');
        $bbSet = BasicBlockHelper::append($context, 'dom_acls_zero_set');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_zero_done');
        $context->builder->branchIf($missing, $bbSkip, $bbSet);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbSet);
        $zero = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $zero,
            JITVariable::TYPE_NATIVE_LONG
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function ensureLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
            // firstChild/lastChild are defined on DOMElement above (createElement layout;
            // DOMNode indices clobber tagName, #32361). childNodes stays on DOMNode.
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

    private static function storeChildEdge(
        Context $context,
        Value $parent,
        string $prop,
        JITVariable $value
    ): void {
        // DOMElement — createElement layout; DOMNode first/last clobbers tagName (#32361).
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
        // DOMElement only — DOMNode nextSibling aliases parentNode (#27476).
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

    /**
     * Bump an existing childNodes list by 1 (or seed length=2) without replacing
     * the list object — held `$list = $node->childNodes` must observe the update
     * (#29048, php-src nodelist.c live collection).
     */
    private static function incrementChildNodesLengthInPlace(
        Context $context,
        Value $owner,
        Value $item0,
        Value $item1
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_inc_len');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $nodeClassId = $objectType->lookup('DOMNode');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        // Element parents — childNodes lives on DOMElement layout (#24973).
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
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

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_acls_inc_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_acls_inc_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_inc_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        // No prior list — seed from pins. Empty→first-child seeds length=1 (#32834);
        // append-tail seed (rare) keeps length=2 when item1 is set (#29048).
        $item1Null = $context->builder->icmp(Builder::INT_EQ, $item1, $objPtrTy->constNull());
        $bbSeed1 = BasicBlockHelper::append($context, 'dom_acls_inc_seed1');
        $bbSeed2 = BasicBlockHelper::append($context, 'dom_acls_inc_seed2');
        $context->builder->branchIf($item1Null, $bbSeed1, $bbSeed2);
        $context->builder->positionAtEnd($bbSeed1);
        self::writeChildNodesList($context, $owner, 1, $item0, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSeed2);
        self::writeChildNodesList($context, $owner, 2, $item0, $item1);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBump);
        $lengthVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $existing,
            'DOMNodeList',
            'length',
            $listClassId
        );
        $current = $context->helper->loadValue($lengthVar);
        $next = $context->builder->add($current, $i64->constInt(1, false));
        $nextJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $next);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $nextJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            self::objectOrNullVar($context, $item0),
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            self::objectOrNullVar($context, $item1),
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * Same-parent appendChild move: refresh `__phpcItem*` pins on the existing
     * childNodes list without changing length (#32929 / peer #32784).
     */
    private static function refreshChildNodesPinsInPlace(
        Context $context,
        Value $owner,
        Value $item0,
        Value $item1
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_refresh_pins');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $objPtrTy = $context->getTypeFromString('__object__*');
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        foreach (['__phpcItem0', '__phpcItem1'] as $prop) {
            if (!$objectType->hasProperty($listClassId, $prop)) {
                $objectType->defineProperty($listClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_acls_refresh_seed');
        $bbPins = BasicBlockHelper::append($context, 'dom_acls_refresh_set');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_refresh_done');
        $context->builder->branchIf($missing, $bbSeed, $bbPins);

        $context->builder->positionAtEnd($bbSeed);
        $item1Null = $context->builder->icmp(Builder::INT_EQ, $item1, $objPtrTy->constNull());
        $bbSeed1 = BasicBlockHelper::append($context, 'dom_acls_refresh_seed1');
        $bbSeed2 = BasicBlockHelper::append($context, 'dom_acls_refresh_seed2');
        $context->builder->branchIf($item1Null, $bbSeed1, $bbSeed2);
        $context->builder->positionAtEnd($bbSeed1);
        self::writeChildNodesList($context, $owner, 1, $item0, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSeed2);
        self::writeChildNodesList($context, $owner, 2, $item0, $item1);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbPins);
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            self::objectOrNullVar($context, $item0),
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            self::objectOrNullVar($context, $item1),
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /** Load parent.childNodes object from a TYPE_VALUE slot (null if unset). */
    private static function loadChildNodesListObject(Context $context, Value $owner): Value
    {
        return self::loadLink($context, $owner, 'DOMElement', VmDom::PROP_CHILD_NODES, 'dom_acls_cn');
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
        $bbNull = BasicBlockHelper::append($context, 'dom_acls_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_acls_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_acls_box_merge');
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
