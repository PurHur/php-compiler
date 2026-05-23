<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitFopen
{
    public static function invoke(Context $context, Value $pathStr, Value $modeStr): Value
    {
        $handle = $context->builder->call(
            $context->lookupFunction('__compiler_fopen'),
            $pathStr,
            $modeStr
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'fopen_fail');
        $okBlock = BasicBlockHelper::append($context, 'fopen_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fopen_done');
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
