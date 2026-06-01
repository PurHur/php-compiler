<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\IniRuntime;

/** `@` error-control BEGIN/END_SILENCE MCJIT lowering (issues #3546, #4070). */
final class ErrorSilenceHelper
{
    public static function beginSilence(Context $context): void
    {
        IniRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_begin_silence'));
    }

    public static function endSilence(Context $context): void
    {
        IniRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_end_silence'));
    }
}
