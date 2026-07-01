<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gztell() via __compiler_gztell (#14585). */
final class JitGztell
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_gztell'),
            $handleLong
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $result, $i64->constInt(-1, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'gztell_fail');
        $okBlock = BasicBlockHelper::append($context, 'gztell_ok');
        $doneBlock = BasicBlockHelper::append($context, 'gztell_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $result);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
