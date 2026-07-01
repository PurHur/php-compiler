<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * compact() / extract() helpers for compiled JIT/AOT modules (#10184, #14499, php-in-PHP).
 *
 * SSOT: {@see VmScope} compact warning messages + extract EXTR_* final-name matrix
 * php-src: ext/standard/basic_functions.c — php_compact, php_extract
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

    /** php-src: php_prefix_varname — prefix and key joined by underscore. */
    public static function prefixVarName(string $prefix, string $key): string
    {
        return $prefix.'_'.$key;
    }

    public static function isValidVarName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return 1 === \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    /**
     * @see ext/standard/array.c switch (extract_type) in php_extract
     */
    public static function resolveExtractFinalName(
        string $key,
        bool $varExists,
        int $extractType,
        ?string $prefix,
    ): ?string {
        $finalName = null;

        switch ($extractType) {
            case VmScope::EXTR_IF_EXISTS:
                if (!$varExists) {
                    return null;
                }
                // no break — fall through to EXTR_OVERWRITE

            case VmScope::EXTR_OVERWRITE:
                return $key;

            case VmScope::EXTR_PREFIX_IF_EXISTS:
                if ($varExists) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return null;

            case VmScope::EXTR_PREFIX_SAME:
                if (!$varExists) {
                    $finalName = $key;
                }
                // no break — fall through to EXTR_PREFIX_ALL

            case VmScope::EXTR_PREFIX_ALL:
                if (null === $finalName) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return $finalName;

            case VmScope::EXTR_PREFIX_INVALID:
                if (!self::isValidVarName($key)) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return $key;

            case VmScope::EXTR_SKIP:
            default:
                if (!$varExists) {
                    return $key;
                }

                return null;
        }
    }

    /**
     * JIT bridge: empty string means skip import for this key (#14499).
     *
     * @param int $varExists 0|1 from LLVM isset guard
     */
    public static function resolveExtractTargetName(
        string $key,
        int $varExists,
        int $extractType,
        string $prefix,
    ): string {
        $finalName = self::resolveExtractFinalName(
            $key,
            0 !== $varExists,
            $extractType,
            '' === $prefix ? null : $prefix
        );
        if (null === $finalName || !self::isValidVarName($finalName)) {
            return '';
        }
        if (
            (VmScope::EXTR_OVERWRITE === $extractType || VmScope::EXTR_IF_EXISTS === $extractType)
            && 'GLOBALS' === $finalName
        ) {
            return '';
        }

        return $finalName;
    }
}
