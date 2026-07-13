<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** User-script AOT: live tag-name counts for DOMNodeList::length (#18478). */
final class DomUserScriptLiveTagListLlvm
{
    public const GLOBAL_TAG = '__phpc_dom_us_live_tag';

    public const GLOBAL_COUNT = '__phpc_dom_us_live_tag_count';

    public static function initCount(Context $context, string $tag, int $count): void
    {
        self::ensureGlobals($context);
        $tagStr = $context->builder->load($context->constantStringFromString(strtolower($tag)));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $tagStr
        );
        $context->builder->store($owned, $context->module->getNamedGlobal(self::GLOBAL_TAG));
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($i64->constInt($count, false), $context->module->getNamedGlobal(self::GLOBAL_COUNT));
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
