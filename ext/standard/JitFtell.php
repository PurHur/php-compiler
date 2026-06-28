<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ftell() via __compiler_ftell (issue #1190). */
final class JitFtell
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamReadRuntime::ensureLinked($context);
        $pos = $context->builder->call($context->lookupFunction('__compiler_ftell'), $handleLong);
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_EQ, $pos, $i64->constInt(-1, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'ftell_fail');
        $okBlock = BasicBlockHelper::append($context, 'ftell_ok');
        $doneBlock = BasicBlockHelper::append($context, 'ftell_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $pos);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
