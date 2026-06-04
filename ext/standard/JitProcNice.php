<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for proc_nice() via __compiler_proc_nice (libc nice(3), #5181). */
final class JitProcNice
{
    public static function invoke(Context $context, JITVariable $priorityArg): Value
    {
        $priority = JitLongArg::lower($context, $priorityArg, 'proc_nice() priority');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_proc_nice'),
            $priority
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $i64->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $isTrue);

        return $ptr;
    }
}
