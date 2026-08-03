<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableChunkLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::chunkCopy() for nested php-in-PHP JIT helpers (#27074).
 *
 * Pure LLVM via {@see HashTableChunkLlvm} — must not call ArrayChunkRuntime
 * (NestedJIT of ArrayChunkJitHelper would recurse; peer #23548 COW / #23974 slice / #27067 reverse).
 */
final class HashTableChunkCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('chunkCopy() requires HashTable receiver and size');
        }

        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $size = self::longAsI64($context, $args[1]);
        $preserveArg = $args[2] ?? null;
        $i1 = $context->getTypeFromString('int1');
        $preserve = $i1->constInt(0, false);
        if (null !== $preserveArg && Variable::TYPE_NULL !== $preserveArg->type) {
            $preserve = self::boolAsI1($context, $preserveArg);
        }

        return HashTableChunkLlvm::chunk($context, $ht, $size, $preserve);
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

    private static function boolAsI1(Context $context, Variable $value): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (
            Variable::TYPE_NATIVE_BOOL === $value->type
            || Variable::TYPE_NATIVE_LONG === $value->type
        ) {
            return $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                JitNestedHelperCoerce::scalarToI64(
                    $context,
                    $context->helper->loadValue($value),
                    $context->getTypeFromString('int32')
                ),
                $i64->constInt(0, false)
            );
        }
        if (Variable::TYPE_VALUE === $value->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $value);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $long,
                $i64->constInt(0, false)
            );
        }

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->helper->loadValue($value),
                $context->getTypeFromString('int32')
            ),
            $i64->constInt(0, false)
        );
    }
}
