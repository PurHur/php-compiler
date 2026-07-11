<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gzwrite() via __compiler_gzwrite (#6168). */
final class JitGzwrite
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $dataStr, Value $lengthLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_gzwrite'),
            $handleLong,
            $dataStr,
            $lengthLong
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'gzwrite_fail');
        $okBlock = BasicBlockHelper::append($context, 'gzwrite_ok');
        $doneBlock = BasicBlockHelper::append($context, 'gzwrite_done');
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
