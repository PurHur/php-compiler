<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for bzopen() via __compiler_bzopen (#17301). */
final class JitBz2open
{
    /** @return Value boxed stream handle or boolean false */
    public static function invoke(Context $context, Value $pathStr, Value $modeStr): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $handle = $context->builder->call(
            $context->lookupFunction('__compiler_bzopen'),
            $pathStr,
            $modeStr
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'bzopen_fail');
        $okBlock = BasicBlockHelper::append($context, 'bzopen_ok');
        $doneBlock = BasicBlockHelper::append($context, 'bzopen_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $handle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
