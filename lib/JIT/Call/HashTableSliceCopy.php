<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableSliceLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::sliceCopy() for nested php-in-PHP JIT helpers (#23974 / #12410).
 *
 * Pure LLVM via {@see HashTableSliceLlvm} — must not call ArraySliceRuntime
 * (NestedJIT of ArraySliceJitHelper would recurse; peer #23548 COW).
 */
final class HashTableSliceCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('sliceCopy() requires HashTable receiver and offset');
        }

        $htVar = self::receiverVariable($context, $args[0]);
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $htVar);
        $offset = self::longAsI64($context, $args[1]);
        $lengthArg = $args[2] ?? null;
        $preserveArg = $args[3] ?? null;

        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $hasLength = $i1->constInt(0, false);
        $length = $i64->constInt(0, false);
        if (null !== $lengthArg && Variable::TYPE_NULL !== $lengthArg->type) {
            $hasLength = $i1->constInt(1, false);
            $length = self::longAsI64($context, $lengthArg);
        }
        $preserve = null;
        if (null !== $preserveArg && Variable::TYPE_NULL !== $preserveArg->type) {
            if (
                Variable::TYPE_NATIVE_BOOL === $preserveArg->type
                || Variable::TYPE_NATIVE_LONG === $preserveArg->type
            ) {
                $preserve = $context->builder->icmp(
                    \PHPLLVM\Builder::INT_NE,
                    JitNestedHelperCoerce::scalarToI64(
                        $context,
                        $context->helper->loadValue($preserveArg),
                        $context->getTypeFromString('int32')
                    ),
                    $i64->constInt(0, false)
                );
            } elseif (Variable::TYPE_VALUE === $preserveArg->type) {
                $ptr = JitValueBox::valuePtrFromVariable($context, $preserveArg);
                $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);
                $preserve = $context->builder->icmp(
                    \PHPLLVM\Builder::INT_NE,
                    $long,
                    $i64->constInt(0, false)
                );
            }
        }

        return HashTableSliceLlvm::slice($context, $ht, $offset, $hasLength, $length, $preserve);
    }

    private static function receiverVariable(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $receiver;
        }
        $htPtr = HashTableNestedReceiver::hashtableFromReceiver($context, $receiver);

        return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $htPtr);
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
