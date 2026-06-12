<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GzStreamIo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gzpassthru() via __compiler_gz_passthru (#4657 phase 2). */
final class JitGzpassthru
{
    /** @return Value int bytes written or boolean false */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        GzStreamIo::ensureLinked($context);

        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_gz_passthru'),
            $handleLong
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'gzpassthru_fail');
        $okBlock = BasicBlockHelper::append($context, 'gzpassthru_ok');
        $doneBlock = BasicBlockHelper::append($context, 'gzpassthru_done');
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
