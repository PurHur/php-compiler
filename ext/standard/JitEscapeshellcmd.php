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

/** LLVM lowering for escapeshellcmd() via ProcessRuntime::__compiler_escapeshellcmd (#3417). */
final class JitEscapeshellcmd
{
    /** @return Value */
    public static function invoke(Context $context, Value $argStr): Value
    {
        ProcessRuntime::ensureLinked($context);
        $escaped = $context->builder->call(
            $context->lookupFunction('__compiler_escapeshellcmd'),
            $argStr
        );
        TypeErrorRaise::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $escaped, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'escapeshellcmd_fail');
        $okBlock = BasicBlockHelper::append($context, 'escapeshellcmd_ok');
        $doneBlock = BasicBlockHelper::append($context, 'escapeshellcmd_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $escaped);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
