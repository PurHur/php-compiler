<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * compact() warning text for compiled JIT/AOT modules (#10184, php-in-PHP).
 *
 * SSOT: {@see VmScope} compact undefined-variable messages
 * php-src: ext/standard/basic_functions.c — php_compact
 */
final class ScopeBuiltinJitHelper
{
    public static function compactUndefinedVariableMessage(string $name): string
    {
        return "compact(): Undefined variable \${$name}";
    }

    public static function emitCompactUndefinedVariableWarning(string $name): void
    {
        if ('' === $name) {
            return;
        }
        compiler_language_warning(self::compactUndefinedVariableMessage($name));
    }
}
