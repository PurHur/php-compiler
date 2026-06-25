<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmClockGettime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time ClockInterface lowering for clock_gettime() (#11624).
 */
final class ClockGettimeJit
{
    public static function compileTimeClockId(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }

        return VmClockGettime::resolveClockId($phpVar, 'clock_gettime');
    }
}
