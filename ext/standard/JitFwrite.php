<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fwrite() via __compiler_fwrite (native FILE* writes). */
final class JitFwrite
{
    /** Omitted fwrite() length: write the full string (not -1, which means zero bytes per php-src). */
    public static function lengthWriteAll(Context $context, Value $dataStr): Value
    {
        return $context->builder->call($context->lookupFunction('__string__strlen'), $dataStr);
    }

    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $dataStr, Value $lengthLong): Value
    {
        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_fwrite'),
            $handleLong,
            $dataStr,
            $lengthLong
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fwrite_fail');
        $okBlock = BasicBlockHelper::append($context, 'fwrite_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fwrite_done');
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
