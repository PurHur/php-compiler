<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;

/**
 * compact() / extract() / get_defined_vars() helpers for compiled JIT/AOT modules (#10184, #14499, #14507, php-in-PHP).
 *
 * SSOT: {@see VmScope} compact warning messages + extract EXTR_* final-name matrix
 * php-src: ext/standard/basic_functions.c — php_compact, php_extract, zend_get_defined_vars
 */
final class ScopeBuiltinJitHelper
{
    /**
     * @param string $namesTable NUL-delimited scope variable names (compile-time)
     *
     * @return int variable index, or -1 when none match (LLVM i32 ABI)
     */
    public static function matchNamedVariableIndex(string $name, string $namesTable): int
    {
        $names = \array_values(\array_filter(
            \explode("\0", $namesTable),
            static fn (string $part): bool => '' !== $part
        ));

        return VariableFunctionCall::matchCandidateIndex($name, $names);
    }

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

    /**
     * compact() array arg — collect string names into $dest keys (#14507).
     *
     * @see VmScope::collectCompactNames()
     */
    public static function collectCompactNamesFromHashtable(HashTable $src, HashTable $dest, int $argNum): void
    {
        foreach ($src->iterateKeyed(true) as [, $valueVar]) {
            self::collectCompactNamesFromVariable($dest, $valueVar->resolveIndirect(), $argNum);
        }
    }

    private static function collectCompactNamesFromVariable(HashTable $dest, Variable $var, int $argNum): void
    {
        if (Variable::TYPE_STRING === $var->type) {
            $name = $var->toString();
            if ('' !== $name) {
                $entry = new Variable();
                $entry->string($name);
                $dest->add($name, $entry);
            }

            return;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            foreach ($var->toArray()->iterateKeyed(true) as [, $child]) {
                self::collectCompactNamesFromVariable($dest, $child->resolveIndirect(), $argNum);
            }

            return;
        }

        self::emitCompactInvalidArgumentWarningFromVariable($argNum, $var);
    }

    public static function emitCompactInvalidArgumentWarningFromVariable(int $argNum, Variable $var): void
    {
        compiler_language_warning(
            self::compactInvalidArgumentMessage($argNum, self::vmTypeLabel($var))
        );
    }

    private static function vmTypeLabel(Variable $var): string
    {
        return EnumCaseSupport::typeNameForVariable($var);
    }

    /**
     * get_defined_vars() / compact() — copy a live variable snapshot into $dest (#14507).
     *
     * @see VmScope::getDefinedVars() copyFrom path
     */
    public static function storeVarSnapshotAtStringKey(HashTable $dest, string $key, Variable $value): void
    {
        $resolved = $value->resolveIndirect();
        if ($resolved->isUndefined() || Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        $copy = new Variable();
        $copy->copyFrom($resolved);
        $dest->update($key, $copy);
    }
}
