<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for undefined $GLOBALS['name'] E_WARNING (#17482, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — global-variable warning on $GLOBALS offset read
 * SSOT: {@see ErrorReporter::undefinedGlobalVariable}
 */
final class UndefinedGlobalVariableJitHelper
{
    public static function warningMessage(string $name): string
    {
        return "Undefined global variable \${$name}";
    }

    /** Emit Zend E_WARNING; compiled into JIT/AOT via UndefinedGlobalVariableRuntime bridge. */
    public static function emitWarning(string $name): void
    {
        if ('' === $name) {
            return;
        }
        compiler_language_warning(self::warningMessage($name));
    }
}
