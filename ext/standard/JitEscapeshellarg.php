<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ProcessRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for escapeshellarg() via ProcessRuntime::__compiler_escapeshellarg (#2779). */
final class JitEscapeshellarg
{
    /** @return Value */
    public static function invoke(Context $context, Value $argStr): Value
    {
        ProcessRuntime::ensureLinked($context);
        $quoted = $context->builder->call(
            $context->lookupFunction('__compiler_escapeshellarg'),
            $argStr
        );
        TypeErrorRaise::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $quoted, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'escapeshellarg_fail');
        $okBlock = BasicBlockHelper::append($context, 'escapeshellarg_ok');
        $doneBlock = BasicBlockHelper::append($context, 'escapeshellarg_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $quoted);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
