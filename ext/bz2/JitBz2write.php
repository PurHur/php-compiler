<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for bzwrite() via __compiler_bzwrite (#17301). */
final class JitBz2write
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $dataStr, Value $lengthLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_bzwrite'),
            $handleLong,
            $dataStr,
            $lengthLong
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'bzwrite_fail');
        $okBlock = BasicBlockHelper::append($context, 'bzwrite_ok');
        $doneBlock = BasicBlockHelper::append($context, 'bzwrite_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $bytes);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
