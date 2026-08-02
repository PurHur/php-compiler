<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReverseLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::reverseCopy() for nested php-in-PHP JIT helpers (#27067).
 *
 * Pure LLVM via {@see HashTableReverseLlvm} — must not call ArrayReverseRuntime
 * (NestedJIT of ArrayReverseJitHelper would recurse; peer #23548 COW / #23974 slice).
 */
final class HashTableReverseCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('reverseCopy() requires a HashTable receiver');
        }

        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $preserveArg = $args[1] ?? null;
        $i1 = $context->getTypeFromString('int1');
        $preserve = $i1->constInt(0, false);
        if (null !== $preserveArg && Variable::TYPE_NULL !== $preserveArg->type) {
            $preserve = self::boolAsI1($context, $preserveArg);
        }

        return HashTableReverseLlvm::reverse($context, $ht, $preserve);
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
        if (Variable::TYPE_INTEGER === $value->type && Variable::KIND_LITERAL === $value->kind) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt((int) $value->literal !== 0 ? 1 : 0, false);
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
