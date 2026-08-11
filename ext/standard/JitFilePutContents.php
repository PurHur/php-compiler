<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for file_put_contents() via {@see \PHPCompiler\JIT\Builtin\StringFilePutContents}.
 *
 * NestedJIT leaf: {@see JitFilePutContentsLibc} so `@file_put_contents` does not re-enter
 * {@see FilePutContentsJitHelper} via `__compiler_file_put_contents` (#30127 / #29833).
 */
final class JitFilePutContents
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $dataStr, Value $flagsLong): Value
    {
        $bytes = NestedJitCompileScope::isActive()
            ? JitFilePutContentsLibc::call($context, $pathStr, $dataStr, $flagsLong)
            : $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataStr,
                $flagsLong
            );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fpc_fail');
        $okBlock = BasicBlockHelper::append($context, 'fpc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fpc_done');
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
