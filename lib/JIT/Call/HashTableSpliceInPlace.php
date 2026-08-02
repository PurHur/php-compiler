<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableSpliceLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::spliceInPlace() for nested php-in-PHP JIT helpers (#27075).
 *
 * Pure LLVM via {@see HashTableSpliceLlvm} — must not call ArraySpliceRuntime
 * (NestedJIT of ArraySpliceJitHelper would recurse; peer #27067 reverse / #23974 slice).
 */
final class HashTableSpliceInPlace implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('spliceInPlace() requires HashTable receiver and offset');
        }

        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $offset = self::longAsI64($context, $args[1]);
        $lengthArg = $args[2] ?? null;
        $replArg = $args[3] ?? null;

        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $hasLength = $i1->constInt(0, false);
        $length = $i64->constInt(0, false);
        if (null !== $lengthArg && Variable::TYPE_NULL !== $lengthArg->type) {
            $hasLength = $i1->constInt(1, false);
            $length = self::longAsI64($context, $lengthArg);
        }
        $hasRepl = $i1->constInt(0, false);
        $replHt = $htPtr->constNull();
        if (null !== $replArg && Variable::TYPE_NULL !== $replArg->type) {
            $hasRepl = $i1->constInt(1, false);
            $replHt = HashTableHelper::loadHashtablePointer(
                $context,
                HashTableHelper::coerceToPackedHashtable($context, $replArg)
            );
        }

        return HashTableSpliceLlvm::spliceInPlace(
            $context,
            $ht,
            $offset,
            $hasLength,
            $length,
            $hasRepl,
            $replHt
        );
    }

    private static function longAsI64(Context $context, Variable $value): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (Variable::TYPE_NATIVE_LONG === $value->type && null !== $value->compileTimeLong) {
            return $i64->constInt($value->compileTimeLong, false);
        }
        if (Variable::TYPE_VALUE === $value->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $value);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return JitNestedHelperCoerce::scalarToI64($context, $long, $i64);
        }

        return JitNestedHelperCoerce::scalarToI64(
            $context,
            $context->helper->loadValue($value),
            $context->getTypeFromString('int32')
        );
    }
}
