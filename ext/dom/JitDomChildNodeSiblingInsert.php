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
 * Thin-AOT ChildNode::before/after when parent may be DOMDocument (#32611).
 *
 * php-src: ext/dom/php_dom.c dom_add_prev_sibling / dom_add_next_sibling
 */
final class JitDomChildNodeSiblingInsert
{
    public static function invokeBefore(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $anchorVar
    ): void {
        JitDomInsertBefore::syncUserScriptInsertBeforeSlotsPublic(
            $context,
            $parentVar,
            $newChildVar,
            $anchorVar
        );
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
    }

    public static function invokeAfter(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $anchorVar
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cn_after');
        $parent = JitDomParentChildLinkLayout::loadObjectArg($context, $parentVar);
        $newChild = JitDomParentChildLinkLayout::loadObjectArg($context, $newChildVar);
        $anchor = JitDomParentChildLinkLayout::loadObjectArg($context, $anchorVar);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $next = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $anchor,
            VmDom::PROP_NEXT_SIBLING,
            'dom_cn_after_next'
        );
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $bbAppend = BasicBlockHelper::append($context, 'dom_cn_after_append');
        $bbInsert = BasicBlockHelper::append($context, 'dom_cn_after_insert');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_after_done');
        $context->builder->branchIf($nextNull, $bbAppend, $bbInsert);

        $context->builder->positionAtEnd($bbInsert);
        $nextJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $next);
        JitDomInsertBefore::syncUserScriptInsertBeforeSlotsPublic(
            $context,
            $parentVar,
            $newChildVar,
            $nextJit
        );
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
        // Must terminate bbInsert — previously fell through by repositioning only,
        // so subsequent user-script IR was unreachable (#32817).
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbAppend);
        self::insertAfterLast($context, $parent, $newChild, $anchor);
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function insertAfterLast(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $anchor
    ): void {
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $anchorJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $anchor);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);

        JitDomParentChildLinkLayout::storeSibling($context, $anchor, VmDom::PROP_NEXT_SIBLING, $newJit);
        JitDomParentChildLinkLayout::storeSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, $anchorJit);
        JitDomParentChildLinkLayout::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, self::nullValueVar($context));
        JitDomParentChildLinkLayout::storeLastChild($context, $parent, $newJit);
        JitDomParentChildLinkLayout::storeParentNode($context, $newChild, $parentJit);

        JitDomInsertBefore::bumpChildNodesLengthPublic($context, $parent, $anchor, $newChild);
        // Append-tail (anchor.next was null) previously only bumped LiveSlots length.
        // saveXML still reads PROP_USER_SCRIPT_INNER_XML — without a rebuild the new
        // sibling is dropped from markup while childNodes->item() sees it (peer
        // insertBefore LiveSlots #33450 / #32940). Middle after() already rebuilds
        // via syncUserScriptInsertBeforeSlotsPublic.
        // Document parents: skip Element INNER_XML rebuild (#33584 / #32611) — Element
        // GEPs on Document layout SIGSEGV.
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, 'dom_cn_after_parent');
        $bbSkipRebuild = BasicBlockHelper::append($context, 'dom_cn_after_skip_rebuild');
        $bbRebuild = BasicBlockHelper::append($context, 'dom_cn_after_rebuild');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_after_rebuild_done');
        $context->builder->branchIf($isDoc, $bbSkipRebuild, $bbRebuild);
        $context->builder->positionAtEnd($bbRebuild);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parent);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSkipRebuild);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
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
}
