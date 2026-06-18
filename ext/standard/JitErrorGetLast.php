<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\LastErrorRuntime;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for error_get_last() / error_clear_last() (issue #3158, #1492). */
final class JitErrorGetLast
{
    public static function invoke(Context $context): Value
    {
        LastErrorRuntime::ensureLinked($context);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $i32 = $context->getTypeFromString('int32');
        $active = $context->builder->call($context->lookupFunction('__phpc_last_error_is_active'));
        $hasError = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zext($active, $i32),
            $i32->constInt(0, false)
        );

        $emptyBb = BasicBlockHelper::append($context, 'error_get_last_empty');
        $workBb = BasicBlockHelper::append($context, 'error_get_last_work');
        $doneBb = BasicBlockHelper::append($context, 'error_get_last_done');
        $context->builder->branchIf($hasError, $workBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $ht = $context->builder->call($context->lookupFunction('__phpc_last_error_to_hashtable'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $ht
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $resultPtr;
    }

    public static function clear(Context $context): void
    {
        LastErrorRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__phpc_last_error_clear'));
    }
}
