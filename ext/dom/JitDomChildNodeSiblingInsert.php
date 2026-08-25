<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;

/**
 * Thin-AOT ChildNode::before/after when parent may be DOMDocument (#32611).
 *
 * php-src: ext/dom/parentnode.c dom_parent_node_after/before (viable_next skip #34791);
 * libxml xmlAddPrevSibling / xmlAddNextSibling for the insert path.
 * Same-parent after() onto last child uses AppendChildLiveSlots unlink (#34804 /
 * peer insertBefore #34803 / appendChild move #27476).
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
        $bbMaybeInsert = BasicBlockHelper::append($context, 'dom_cn_after_maybe_insert');
        $bbAlreadyNext = BasicBlockHelper::append($context, 'dom_cn_after_already_next');
        $bbInsert = BasicBlockHelper::append($context, 'dom_cn_after_insert');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_after_done');
        $context->builder->branchIf($nextNull, $bbAppend, $bbMaybeInsert);

        // Already immediately after the anchor → no-op (php-src viable_next skip; #34791).
        // insertBefore(new, next) with new===next would throw identity Error.
        // Still rebuild INNER_XML: trySyncSiblingInsertInnerXml may have spliced a
        // duplicate compile-time tag before LiveSlots ran.
        $context->builder->positionAtEnd($bbMaybeInsert);
        $alreadyNext = $context->builder->icmp(Builder::INT_EQ, $next, $newChild);
        $context->builder->branchIf($alreadyNext, $bbAlreadyNext, $bbInsert);

        $context->builder->positionAtEnd($bbAlreadyNext);
        $isDocAlready = JitDomParentChildLinkLayout::isDocumentObject($context, $parent, 'dom_cn_after_already_doc');
        $bbSkipAlreadyRebuild = BasicBlockHelper::append($context, 'dom_cn_after_already_skip_rebuild');
        $bbAlreadyRebuild = BasicBlockHelper::append($context, 'dom_cn_after_already_rebuild');
        $context->builder->branchIf($isDocAlready, $bbSkipAlreadyRebuild, $bbAlreadyRebuild);
        $context->builder->positionAtEnd($bbAlreadyRebuild);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parent);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSkipAlreadyRebuild);
        $context->builder->branch($bbDone);

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
        // Anchor was lastChild (next==null). insertAfterLast linked without unlinking a
        // same-parent earlier sibling → next/prev cycle + childNodes SIGSEGV (#34804 /
        // peer insertBefore unlink #34803 / appendChild move #27476). Append LiveSlots
        // already unlinks same-parent members before splice.
        JitDomAppendChildLiveSlots::sync($context, $parent, $newChild);
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }
}
