<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT: live tag-name counts for DOMNodeList::length (#18478, #28605, #33659, #33679).
 *
 * {@code getElementsByTagName('*')} must bump on any element append (#33659) —
 * comparing the child local name to {@code *} never matches. Appends before the
 * first query accumulate in {@see GLOBAL_PENDING} and fold into {@see initCount}.
 * {@see removeChild} must decrement (#33679); {@code insertBefore} must not both
 * bump pending and refresh compile-time XML (double-count).
 */
final class DomUserScriptLiveTagListLlvm
{
    public const GLOBAL_TAG = '__phpc_dom_us_live_tag';

    public const GLOBAL_COUNT = '__phpc_dom_us_live_tag_count';

    /** Element appends before any getElementsByTagName query (#33659). */
    public const GLOBAL_PENDING = '__phpc_dom_us_live_tag_pending';

    /**
     * Seed live tag + count from compile-time XML.
     *
     * Re-querying the same tag must keep mutation increments from appendChild
     * (#28605); only retarget when GLOBAL_TAG is unset or a different name.
     * First query folds {@see GLOBAL_PENDING} so append-then-query sees created
     * elements (#33659).
     *
     * Pass {@see $force} for XPath snapshots (#28647): each query()/evaluate()
     * NodeList is not live, so same-tag reuse must still rewrite the count.
     */
    public static function initCount(Context $context, string $tag, int $count, bool $force = false): void
    {
        self::ensureGlobals($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_us_live_tag_init_cont');

        $tagStr = $context->builder->load($context->constantStringFromString(strtolower($tag)));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $tagStr
        );
        $i64 = $context->getTypeFromString('int64');
        $tagGlobal = $context->module->getNamedGlobal(self::GLOBAL_TAG);
        $countGlobal = $context->module->getNamedGlobal(self::GLOBAL_COUNT);
        $pendingGlobal = $context->module->getNamedGlobal(self::GLOBAL_PENDING);
        $doInit = BasicBlockHelper::append($context, 'dom_us_live_tag_do_init');
        $done = BasicBlockHelper::append($context, 'dom_us_live_tag_init_done');

        if ($force) {
            $context->builder->branch($doInit);
        } else {
            $storedTag = $context->builder->load($tagGlobal);
            $hasTag = $context->builder->icmp(
                Builder::INT_NE,
                $storedTag,
                $storedTag->typeOf()->constNull()
            );

            $checkSame = BasicBlockHelper::append($context, 'dom_us_live_tag_check_same');
            $context->builder->branchIf($hasTag, $checkSame, $doInit);

            $context->builder->positionAtEnd($checkSame);
            $cmp = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $tagStr, $storedTag);
            $same = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
            $context->builder->branchIf($same, $done, $doInit);
        }

        $context->builder->positionAtEnd($doInit);
        $context->builder->store($owned, $tagGlobal);
        $pending = $context->builder->load($pendingGlobal);
        $base = $i64->constInt($count, false);
        $total = $context->builder->add($base, $pending);
        $context->builder->store($total, $countGlobal);
        $context->builder->store($i64->constInt(0, false), $pendingGlobal);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function readStoredCount(Context $context): Value
    {
        self::ensureGlobals($context);

        return $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_COUNT));
    }

    public static function increment(Context $context, Value $tagStr): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $storedTag = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_TAG));
        $tagNull = $storedTag->typeOf()->constNull();
        $hasTag = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $storedTag,
            $tagNull
        );
        $cmp = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $tagStr, $storedTag);
        $tagMatch = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $match = $context->builder->and($hasTag, $tagMatch);
        $storedCount = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_COUNT));
        $next = $context->builder->add($storedCount, $one);
        $updated = $context->builder->select($match, $next, $storedCount);
        $context->builder->store($updated, $context->module->getNamedGlobal(self::GLOBAL_COUNT));
    }

    /** Bump live tag count when child tag is unknown at compile time (#19208). */
    public static function incrementCount(Context $context): void
    {
        self::ensureGlobals($context);
        $countGlobal = $context->module->getNamedGlobal(self::GLOBAL_COUNT);
        if (null === $countGlobal) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $storedCount = $context->builder->load($countGlobal);
        $context->builder->store(
            $context->builder->add($storedCount, $i64->constInt(1, false)),
            $countGlobal
        );
    }

    /**
     * Note an element entering the tree (appendChild / after / before / prepend).
     *
     * {@code *} and empty query tags match any element (#33659). Appends before
     * the first query go to {@see GLOBAL_PENDING}.
     *
     * Deep importNode/cloneNode subtrees must bump by descendant element count
     * (xmlDocCopyNode), not +1 for the root only — otherwise
     * {@code getElementsByTagName('*')} misses nested children after append.
     */
    public static function incrementForChildArg(Context $context, \PHPCompiler\JIT\Variable $childArg): void
    {
        self::ensureGlobals($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_us_live_tag_child_inc');
        $i64 = $context->getTypeFromString('int64');
        $deltaN = self::compileTimeSubtreeElementCount($childArg);
        if ($deltaN <= 0) {
            return;
        }
        $delta = $i64->constInt($deltaN, false);
        $tagGlobal = $context->module->getNamedGlobal(self::GLOBAL_TAG);
        $countGlobal = $context->module->getNamedGlobal(self::GLOBAL_COUNT);
        $pendingGlobal = $context->module->getNamedGlobal(self::GLOBAL_PENDING);
        $storedTag = $context->builder->load($tagGlobal);
        $hasTag = $context->builder->icmp(
            Builder::INT_NE,
            $storedTag,
            $storedTag->typeOf()->constNull()
        );

        $bbPending = BasicBlockHelper::append($context, 'dom_us_live_tag_child_pending');
        $bbActive = BasicBlockHelper::append($context, 'dom_us_live_tag_child_active');
        $bbDone = BasicBlockHelper::append($context, 'dom_us_live_tag_child_done');
        $context->builder->branchIf($hasTag, $bbActive, $bbPending);

        $context->builder->positionAtEnd($bbPending);
        $pending = $context->builder->load($pendingGlobal);
        $context->builder->store($context->builder->add($pending, $delta), $pendingGlobal);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbActive);
        $starLit = $context->builder->load($context->constantStringFromString('*'));
        $emptyLit = $context->builder->load($context->constantStringFromString(''));
        $cmpStar = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $storedTag, $starLit);
        $isStar = $context->builder->icmp(Builder::INT_EQ, $cmpStar, $i64->constInt(0, false));
        $cmpEmpty = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $storedTag, $emptyLit);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $cmpEmpty, $i64->constInt(0, false));
        $anyElement = $context->builder->or($isStar, $isEmpty);

        $bbAny = BasicBlockHelper::append($context, 'dom_us_live_tag_child_any');
        $bbExact = BasicBlockHelper::append($context, 'dom_us_live_tag_child_exact');
        $context->builder->branchIf($anyElement, $bbAny, $bbExact);

        $context->builder->positionAtEnd($bbAny);
        $storedCount = $context->builder->load($countGlobal);
        $context->builder->store($context->builder->add($storedCount, $delta), $countGlobal);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbExact);
        // Exact tag: bump once for the child local name (createElement). When a prior
        // getElementsByTagName query is live, count matching names in the subtree.
        $queryTag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $lit = \PHPCompiler\JIT\JitStringBuiltinArg::compileTimeLiteral($childArg)
            ?? $childArg->compileTimeString
            ?? $childArg->compileTimeDomTagName
            ?? JitDomImportNode::$lastMaterializedTagName;
        if (null !== $queryTag && '*' !== $queryTag && '' !== $queryTag) {
            $matchN = self::compileTimeSubtreeTagMatchCount($childArg, $queryTag);
            if ($matchN > 0) {
                $childTag = $context->builder->load(
                    $context->constantStringFromString(strtolower($queryTag))
                );
                for ($i = 0; $i < $matchN; ++$i) {
                    self::increment($context, $childTag);
                }
            }
        } elseif (null !== $lit) {
            $childTag = $context->builder->load(
                $context->constantStringFromString(strtolower($lit))
            );
            self::increment($context, $childTag);
        } else {
            for ($i = 0; $i < $deltaN; ++$i) {
                self::incrementCount($context);
            }
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * How many elements enter the document with this child (deep subtree).
     */
    private static function compileTimeSubtreeElementCount(\PHPCompiler\JIT\Variable $childArg): int
    {
        $tag = $childArg->compileTimeDomTagName
            ?? JitDomImportNode::$lastMaterializedTagName
            ?? JitDomCloneNode::$lastResultTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName;
        if (null === $tag || '' === $tag) {
            return 1;
        }
        if (str_starts_with($tag, '#')) {
            return 0;
        }
        // ARG_SEND / call-result temps often keep an empty InnerXml stamp while
        // importNode/cloneNode still hold the deep materialize on statics.
        $inner = self::resolveChildInnerXml($childArg);
        $outer = '<'.$tag.'>'.($inner ?? '').'</'.$tag.'>';

        return max(1, DomParseSimpleXmlJitHelper::countTagArgv($outer, '*'));
    }

    /**
     * Elements in the entering subtree whose local name matches {@see $queryTag}.
     */
    private static function compileTimeSubtreeTagMatchCount(
        \PHPCompiler\JIT\Variable $childArg,
        ?string $queryTag
    ): int {
        if (null === $queryTag || '' === $queryTag || '*' === $queryTag) {
            return self::compileTimeSubtreeElementCount($childArg);
        }
        $tag = $childArg->compileTimeDomTagName
            ?? JitDomImportNode::$lastMaterializedTagName
            ?? JitDomCloneNode::$lastResultTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName;
        if (null === $tag || '' === $tag || str_starts_with($tag, '#')) {
            return 0;
        }
        $inner = self::resolveChildInnerXml($childArg);
        $outer = '<'.$tag.'>'.($inner ?? '').'</'.$tag.'>';

        return DomParseSimpleXmlJitHelper::countTagArgv($outer, $queryTag);
    }

    /** Prefer non-empty materialize InnerXml over empty ARG_SEND stamps. */
    private static function resolveChildInnerXml(\PHPCompiler\JIT\Variable $childArg): ?string
    {
        $inner = $childArg->compileTimeDomInnerXml;
        if (null !== $inner && '' !== $inner) {
            return $inner;
        }
        if (null !== JitDomImportNode::$lastMaterializedInnerXml
            && '' !== JitDomImportNode::$lastMaterializedInnerXml
        ) {
            return JitDomImportNode::$lastMaterializedInnerXml;
        }
        if (null !== $inner) {
            return $inner;
        }

        return null;
    }

    /**
     * Note an element leaving the tree (removeChild) — mirror of
     * {@see incrementForChildArg} (#33679 leftover of #33659).
     *
     * Pending pre-query appends decrement {@see GLOBAL_PENDING}; active
     * {@code *} / empty / matching-tag queries decrement {@see GLOBAL_COUNT}
     * (floored at 0).
     */
    public static function decrementForChildArg(Context $context, \PHPCompiler\JIT\Variable $childArg): void
    {
        self::ensureGlobals($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_us_live_tag_child_dec');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);
        $tagGlobal = $context->module->getNamedGlobal(self::GLOBAL_TAG);
        $countGlobal = $context->module->getNamedGlobal(self::GLOBAL_COUNT);
        $pendingGlobal = $context->module->getNamedGlobal(self::GLOBAL_PENDING);
        $storedTag = $context->builder->load($tagGlobal);
        $hasTag = $context->builder->icmp(
            Builder::INT_NE,
            $storedTag,
            $storedTag->typeOf()->constNull()
        );

        $bbPending = BasicBlockHelper::append($context, 'dom_us_live_tag_child_dec_pending');
        $bbActive = BasicBlockHelper::append($context, 'dom_us_live_tag_child_dec_active');
        $bbDone = BasicBlockHelper::append($context, 'dom_us_live_tag_child_dec_done');
        $context->builder->branchIf($hasTag, $bbActive, $bbPending);

        $context->builder->positionAtEnd($bbPending);
        $pending = $context->builder->load($pendingGlobal);
        $pendingGt = $context->builder->icmp(Builder::INT_SGT, $pending, $zero);
        $pendingNext = $context->builder->sub($pending, $one);
        $context->builder->store(
            $context->builder->select($pendingGt, $pendingNext, $zero),
            $pendingGlobal
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbActive);
        $starLit = $context->builder->load($context->constantStringFromString('*'));
        $emptyLit = $context->builder->load($context->constantStringFromString(''));
        $cmpStar = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $storedTag, $starLit);
        $isStar = $context->builder->icmp(Builder::INT_EQ, $cmpStar, $i64->constInt(0, false));
        $cmpEmpty = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $storedTag, $emptyLit);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $cmpEmpty, $i64->constInt(0, false));
        $anyElement = $context->builder->or($isStar, $isEmpty);

        $bbAny = BasicBlockHelper::append($context, 'dom_us_live_tag_child_dec_any');
        $bbExact = BasicBlockHelper::append($context, 'dom_us_live_tag_child_dec_exact');
        $context->builder->branchIf($anyElement, $bbAny, $bbExact);

        $context->builder->positionAtEnd($bbAny);
        self::decrementCountFloored($context, $countGlobal, $one, $zero);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbExact);
        $lit = \PHPCompiler\JIT\JitStringBuiltinArg::compileTimeLiteral($childArg)
            ?? $childArg->compileTimeString
            ?? $childArg->compileTimeDomTagName;
        if (null !== $lit) {
            $childTag = $context->builder->load(
                $context->constantStringFromString(strtolower($lit))
            );
            self::decrement($context, $childTag);
        } else {
            self::decrementCountFloored($context, $countGlobal, $one, $zero);
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    public static function decrement(Context $context, Value $tagStr): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);
        $storedTag = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_TAG));
        $tagNull = $storedTag->typeOf()->constNull();
        $hasTag = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $storedTag,
            $tagNull
        );
        $cmp = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $tagStr, $storedTag);
        $tagMatch = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $match = $context->builder->and($hasTag, $tagMatch);
        $countGlobal = $context->module->getNamedGlobal(self::GLOBAL_COUNT);
        $storedCount = $context->builder->load($countGlobal);
        $gt = $context->builder->icmp(Builder::INT_SGT, $storedCount, $zero);
        $next = $context->builder->select($gt, $context->builder->sub($storedCount, $one), $zero);
        $updated = $context->builder->select($match, $next, $storedCount);
        $context->builder->store($updated, $countGlobal);
    }

    private static function decrementCountFloored(
        Context $context,
        Value $countGlobal,
        Value $one,
        Value $zero
    ): void {
        $storedCount = $context->builder->load($countGlobal);
        $gt = $context->builder->icmp(Builder::INT_SGT, $storedCount, $zero);
        $context->builder->store(
            $context->builder->select($gt, $context->builder->sub($storedCount, $one), $zero),
            $countGlobal
        );
    }

    private static function ensureGlobals(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal(self::GLOBAL_TAG)) {
            $g = $context->module->addGlobal($strPtr, self::GLOBAL_TAG);
            $g->setInitializer($strPtr->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_COUNT)) {
            $g = $context->module->addGlobal($i64, self::GLOBAL_COUNT);
            $g->setInitializer($i64->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_PENDING)) {
            $g = $context->module->addGlobal($i64, self::GLOBAL_PENDING);
            $g->setInitializer($i64->constInt(0, false));
        }
    }
}
