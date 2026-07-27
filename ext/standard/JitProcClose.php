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

/** LLVM lowering for proc_close() via __compiler_proc_close (#6904). */
final class JitProcClose
{
    public static function invoke(Context $context, JITVariable $procArg): Value
    {
        // STANDALONE/EMBED AOT skips eager ProcessOpen link (#12910); ensure before lookup (#23722).
        ProcessOpen::ensureLinked($context);
        JitResourceArg::rejectEnumCaseOperand($context, $procArg, 'proc_close', 0, 'process');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $procArg, 'proc_close() process'),
            $context->getTypeFromString('int64')
        );
        $exitCode = $context->builder->call(
            $context->lookupFunction('__compiler_proc_close'),
            $handle
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->sext($exitCode, $context->getTypeFromString('int64'))
        );

        return $ptr;
    }
}
