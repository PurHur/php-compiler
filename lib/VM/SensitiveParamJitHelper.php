<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for #[\SensitiveParameter] backtrace redaction (#10394, php-in-PHP).
 *
 * php-src: Zend/zend_builtin_functions.c — debug_backtrace() arg redaction
 * SSOT: {@see SensitiveParamSupport}
 */
final class SensitiveParamJitHelper
{
    public static function shouldIgnoreBacktraceArgs(int $options): bool
    {
        return 0 !== ($options & SensitiveParamSupport::BACKTRACE_IGNORE_ARGS);
    }

    public static function ignoreArgsOptionMask(): int
    {
        return SensitiveParamSupport::BACKTRACE_IGNORE_ARGS;
    }

    /** @param array<int, true> $sensitive */
    public static function compileTimeParamIsSensitive(array $sensitive, int $paramIdx): bool
    {
        return SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, $paramIdx);
    }

    public static function traceArgLabel(): string
    {
        return SensitiveParamSupport::TRACE_ARG_LABEL;
    }

    public static function markerClassName(): string
    {
        return SensitiveParamSupport::CLASS_NAME;
    }
}
