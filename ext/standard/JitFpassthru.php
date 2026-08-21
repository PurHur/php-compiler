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
 * LLVM lowering for fpassthru() via __compiler_fpassthru (issue #1194; ensureLinked #33113).
 *
 * ABI owned by {@see StreamReadRuntime} after Type always-on drop (#33106) — must
 * ensureLinked before lookup (peer {@see JitFgetc} / {@see JitFlock}).
 * SplFileObject::fpassthru uses the same invoke on `__spl_fd` (#33360).
 */
final class JitFpassthru
{
    /** @return Value
     * (int bytes written, or boolean false on failure) */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamReadRuntime::ensureLinked($context);
        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_fpassthru'),
            $handleLong
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fpassthru_fail');
        $okBlock = BasicBlockHelper::append($context, 'fpassthru_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fpassthru_done');
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
