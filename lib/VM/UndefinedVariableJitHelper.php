<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for undefined-variable E_WARNING on scope reads (#10360, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_CHECK_UNDEFINED_VAR
 * SSOT: {@see ErrorReporter::undefinedVariable}
 */
final class UndefinedVariableJitHelper
{
    public static function warningMessage(string $name): string
    {
        return "Undefined variable \${$name}";
    }

    /** Emit Zend E_WARNING; compiled into JIT/AOT via UndefinedVariableRuntime bridge. */
    public static function emitWarning(string $name): void
    {
        if ('' === $name) {
            return;
        }
        compiler_language_warning(self::warningMessage($name));
    }
}
