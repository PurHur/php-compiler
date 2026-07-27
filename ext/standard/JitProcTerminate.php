<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ProcessOpen;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for proc_terminate() via __compiler_proc_terminate (#3740). */
final class JitProcTerminate
{
    public static function invoke(Context $context, JITVariable $procArg, ?JITVariable $signalArg = null): Value
    {
        // STANDALONE/EMBED AOT skips eager ProcessOpen link (#12910); ensure before lookup (#23722).
        ProcessOpen::ensureLinked($context);
        JitResourceArg::rejectEnumCaseOperand($context, $procArg, 'proc_terminate', 0, 'process');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $procArg, 'proc_terminate() process'),
            $context->getTypeFromString('int64')
        );
        $i32 = $context->getTypeFromString('int32');
        $signal = $i32->constInt(15, false);
        if (null !== $signalArg) {
            $signal = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $signalArg, 'proc_terminate() signal'),
                $i32
            );
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_proc_terminate'),
            $handle,
            $signal
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $ok,
                $i32->constInt(0, false)
            )
        );

        return $ptr;
    }
}
