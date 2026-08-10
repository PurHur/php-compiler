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
 * LLVM lowering for readfile() via {@see \PHPCompiler\JIT\Builtin\StringReadfile}.
 *
 * NestedJIT leaf: {@see JitReadfileLibc} so `@readfile` does not re-enter
 * {@see ReadfileJitHelper} via `__compiler_readfile` (#29915 / #29833).
 */
final class JitReadfile
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        $bytes = NestedJitCompileScope::isActive()
            ? JitReadfileLibc::call($context, $pathStr)
            : $context->builder->call(
                $context->lookupFunction('__compiler_readfile'),
                $pathStr
            );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'readfile_fail');
        $okBlock = BasicBlockHelper::append($context, 'readfile_ok');
        $doneBlock = BasicBlockHelper::append($context, 'readfile_done');
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
