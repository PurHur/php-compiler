<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** User-script AOT: live tag-name counts for DOMNodeList::length (#18478). */
final class DomUserScriptLiveTagListLlvm
{
    public const GLOBAL_TAG = '__phpc_dom_us_live_tag';

    public const GLOBAL_COUNT = '__phpc_dom_us_live_tag_count';

    /**
     * Seed live tag + count from compile-time XML.
     *
     * Re-querying the same tag must keep mutation increments from appendChild
     * (#28605); only retarget when GLOBAL_TAG is unset or a different name.
     */
    public static function initCount(Context $context, string $tag, int $count): void
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
        $storedTag = $context->builder->load($tagGlobal);
        $hasTag = $context->builder->icmp(
            Builder::INT_NE,
            $storedTag,
            $storedTag->typeOf()->constNull()
        );

        $checkSame = BasicBlockHelper::append($context, 'dom_us_live_tag_check_same');
        $doInit = BasicBlockHelper::append($context, 'dom_us_live_tag_do_init');
        $done = BasicBlockHelper::append($context, 'dom_us_live_tag_init_done');
        $context->builder->branchIf($hasTag, $checkSame, $doInit);

        $context->builder->positionAtEnd($checkSame);
        $cmp = \PHPCompiler\JIT\JitStringCompare::strcmp($context, $tagStr, $storedTag);
        $same = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $context->builder->branchIf($same, $done, $doInit);

        $context->builder->positionAtEnd($doInit);
        $context->builder->store($owned, $tagGlobal);
        $context->builder->store($i64->constInt($count, false), $countGlobal);
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

    public static function incrementForChildArg(Context $context, \PHPCompiler\JIT\Variable $childArg): void
    {
        if (null === $context->module->getNamedGlobal(self::GLOBAL_TAG)
            || null === $context->module->getNamedGlobal(self::GLOBAL_COUNT)
        ) {
            return;
        }
        $lit = \PHPCompiler\JIT\JitStringBuiltinArg::compileTimeLiteral($childArg)
            ?? $childArg->compileTimeString;
        if (null !== $lit) {
            $childTag = $context->builder->load(
                $context->constantStringFromString(strtolower($lit))
            );
            self::increment($context, $childTag);

            return;
        }
        self::incrementCount($context);
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
    }
}
