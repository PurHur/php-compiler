<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Per-scope-variable assigned flags for JIT undefined-variable guards (#10360).
 *
 * Flags are per-activation entry allocas (Zend frame initializedSlots), not module
 * globals — a process-wide bit breaks recursion semantics (#36190).
 */
final class ScopeVariableAssignedFlags
{
    /** @var array<string, Value> entry-block i8 alloca per flag key */
    private static array $flags = [];

    public static function flagKey(Context $context, string $name): string
    {
        $resolved = $context->resolveRefAliasName($name);
        $block = $context->jitEnclosingBlock ?? $context->jitFunctionRootBlock;
        if (null !== $block && $block->isMainScript()) {
            // {main} CV flags must not key off activeFunction — nested class/method
            // lowering can leave it stale while still emitting main-body guards (#31835 / #36081).
            return '{main}'."\0".$resolved;
        }

        return $context->activeFunction."\0".$resolved;
    }

    public static function ensureFlag(Context $context, string $key): Value
    {
        if (!isset(self::$flags[$key])) {
            $i8 = $context->getTypeFromString('int8');
            $fn = BasicBlockHelper::parentFunction($context);
            $flag = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8);
            BasicBlockHelper::storeAtFunctionEntry(
                $context,
                $fn,
                $i8->constInt(0, false),
                $flag
            );
            self::$flags[$key] = $flag;
        }

        return self::$flags[$key];
    }

    public static function markAssigned(Context $context, string $key): void
    {
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i8->constInt(1, false), self::ensureFlag($context, $key));
    }

    public static function isAssignedCondition(Context $context, string $key): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $loaded = $context->builder->load(self::ensureFlag($context, $key));

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $i8->constInt(0, false)
        );
    }
}
