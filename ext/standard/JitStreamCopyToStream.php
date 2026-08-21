<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for stream_copy_to_stream() via __compiler_stream_copy_to_stream (#3272; ensureLinked #33182).
 *
 * ABI owned by {@see StreamReadRuntime} / {@see JitStreamReadBridgeKernel} after Type always-on
 * drop (#33182) — must ensureLinked before lookup (peer {@see JitFseek} / {@see JitStreamGetContents}).
 */
final class JitStreamCopyToStream
{
    /** @return Value (int bytes copied, or boolean false on failure) */
    public static function invoke(
        Context $context,
        Value $sourceLong,
        Value $destLong,
        Value $maxlengthLong,
        Value $offsetLong,
    ): Value {
        StreamReadRuntime::ensureLinked($context);
        $copied = $context->builder->call(
            $context->lookupFunction('__compiler_stream_copy_to_stream'),
            $sourceLong,
            $destLong,
            $maxlengthLong,
            $offsetLong
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $copied, $i64->constInt(0, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'stream_copy_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_copy_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_copy_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $copied);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
