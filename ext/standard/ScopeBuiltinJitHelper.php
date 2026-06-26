<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * compact() warning text for compiled JIT/AOT modules (#10184, php-in-PHP).
 *
 * SSOT: {@see VmScope} compact warning messages
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

    public static function jitTypeLabel(int $jitTypeByte): string
    {
        return match ($jitTypeByte) {
            JitVariable::TYPE_NULL => 'null',
            JitVariable::TYPE_NATIVE_LONG => 'int',
            JitVariable::TYPE_NATIVE_DOUBLE => 'float',
            JitVariable::TYPE_NATIVE_BOOL => 'bool',
            JitVariable::TYPE_STRING => 'string',
            JitVariable::TYPE_HASHTABLE => 'array',
            JitVariable::TYPE_OBJECT => 'object',
            default => 'unknown type',
        };
    }

    public static function compactInvalidArgumentMessage(int $argNum, string $typeName): string
    {
        return "compact(): Argument #{$argNum} must be string or array of strings, {$typeName} given";
    }

    public static function emitCompactInvalidArgumentWarning(int $argNum, int $jitTypeByte): void
    {
        compiler_language_warning(
            self::compactInvalidArgumentMessage($argNum, self::jitTypeLabel($jitTypeByte))
        );
    }
}
