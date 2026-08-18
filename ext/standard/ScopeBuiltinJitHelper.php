<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\Web\Superglobals;

/**
 * compact() / extract() / get_defined_vars() helpers for compiled JIT/AOT modules (#10184, #14499, #14507, php-in-PHP).
 *
 * SSOT: {@see VmScope} compact warning messages + extract EXTR_* final-name matrix
 * php-src: ext/standard/basic_functions.c — php_compact, php_extract, zend_get_defined_vars
 */
final class ScopeBuiltinJitHelper
{
    /** php-src: php_extract() — zend_throw_error(NULL, "Cannot re-assign $this") (#32226). */
    public const EXTRACT_THIS_REASSIGN_ERROR = 'Cannot re-assign $this';

    /**
     * @param string $namesTable NUL-delimited scope variable names (compile-time)
     *
     * @return int variable index, or -1 when none match (LLVM i32 ABI)
     */
    public static function matchNamedVariableIndex(string $name, string $namesTable): int
    {
        // NestedJIT cannot lower array_filter with a callback in this helper (#27520).
        $names = [];
        foreach (\explode("\0", $namesTable) as $part) {
            if ('' !== $part) {
                $names[] = $part;
            }
        }

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

    public static function jitTypeLabel(int $jitTypeByte, int $boolPayload = 0): string
    {
        return match ($jitTypeByte) {
            JitVariable::TYPE_NULL => 'null',
            JitVariable::TYPE_NATIVE_LONG => 'int',
            JitVariable::TYPE_NATIVE_DOUBLE => 'float',
            // Zend compact() Warning: zend_zval_value_name → false|true (#30119).
            JitVariable::TYPE_NATIVE_BOOL => 0 !== $boolPayload ? 'true' : 'false',
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

    public static function emitCompactInvalidArgumentWarning(int $argNum, int $jitTypeByte, int $boolPayload = 0): void
    {
        compiler_language_warning(
            self::compactInvalidArgumentMessage($argNum, self::jitTypeLabel($jitTypeByte, $boolPayload))
        );
    }

    /** php-src: php_prefix_varname — prefix and key joined by underscore. */
    public static function prefixVarName(string $prefix, string $key): string
    {
        return $prefix.'_'.$key;
    }

    /**
     * php_extract_overwrite / if_exists / prefix that resolves to `this`.
     *
     * EXTR_SKIP never imports `this` (bug #77135); other types throw.
     */
    public static function rejectExtractThis(string $finalName): void
    {
        if ('this' === $finalName) {
            throw new \Error(self::EXTRACT_THIS_REASSIGN_ERROR);
        }
    }

    public static function isValidVarName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        // NestedJIT-safe: no preg_match → `__compiler_preg_match` in helper-runtime unit.o (#27520 / #30778).
        $c0 = $name[0];
        if (!(('a' <= $c0 && $c0 <= 'z') || ('A' <= $c0 && $c0 <= 'Z') || '_' === $c0)) {
            return false;
        }
        $len = \strlen($name);
        for ($i = 1; $i < $len; ++$i) {
            $c = $name[$i];
            if (!(('a' <= $c && $c <= 'z') || ('A' <= $c && $c <= 'Z') || ('0' <= $c && $c <= '9') || '_' === $c)) {
                return false;
            }
        }

        return true;
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
        // NestedJIT: keep $prefixArg as string ('' = absent) — avoid null ternaries (#27520).
        $prefixArg = null === $prefix ? '' : $prefix;

        switch ($extractType) {
            case VmScope::EXTR_IF_EXISTS:
                if (!$varExists) {
                    return null;
                }
                // no break — fall through to EXTR_OVERWRITE

            case VmScope::EXTR_OVERWRITE:
                return $key;

            case VmScope::EXTR_PREFIX_IF_EXISTS:
                // php_extract: set → prefixed; absent/IS_UNDEF → import unprefixed (#24330).
                if ($varExists) {
                    return self::prefixVarName($prefixArg, $key);
                }

                return $key;

            case VmScope::EXTR_PREFIX_SAME:
                if (!$varExists) {
                    $finalName = $key;
                }
                // no break — fall through to EXTR_PREFIX_ALL

            case VmScope::EXTR_PREFIX_ALL:
                if (null === $finalName) {
                    return self::prefixVarName($prefixArg, $key);
                }

                return $finalName;

            case VmScope::EXTR_PREFIX_INVALID:
                // php_extract_prefix_invalid: "this" is treated as an invalid name and prefixed.
                if (!self::isValidVarName($key) || 'this' === $key) {
                    return self::prefixVarName($prefixArg, $key);
                }

                return $key;

            case VmScope::EXTR_SKIP:
            default:
                // php_extract_skip: never import `this` even when the CV is unset (#77135).
                if ('this' === $key) {
                    return null;
                }
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
        // Always pass string prefix into final-name matrix ('' = absent) (#27520).
        $finalName = self::resolveExtractFinalName(
            $key,
            0 !== $varExists,
            $extractType,
            $prefix
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
     * compact() array arg — collect string names into indexed $dest list (#14507, #19035).
     *
     * @see VmScope::collectCompactNames()
     */
    public static function collectCompactNamesFromHashtable(HashTable $src, HashTable $dest, int $argNum): void
    {
        $index = 0;
        foreach ($src->iterateKeyed(true) as [, $valueVar]) {
            $index = self::collectCompactNamesFromVariable($dest, $valueVar->resolveIndirect(), $argNum, $index);
        }
    }

    private static function collectCompactNamesFromVariable(HashTable $dest, Variable $var, int $argNum, int $index): int
    {
        if (Variable::TYPE_STRING === $var->type) {
            $name = $var->toString();
            if ('' !== $name) {
                $entry = new Variable();
                $entry->string($name);
                $dest->addIndex($index, $entry);
                ++$index;
            }

            return $index;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            foreach ($var->toArray()->iterateKeyed(true) as [, $child]) {
                $index = self::collectCompactNamesFromVariable($dest, $child->resolveIndirect(), $argNum, $index);
            }

            return $index;
        }

        self::emitCompactInvalidArgumentWarningFromVariable($argNum, $var);

        return $index;
    }

    /** php-src zend_hash string-key walk SSOT — parity guard for LLVM walks (#19035). */
    public static function foreachNonEmptyStringKey(HashTable $ht, callable $body): void
    {
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $name = $keyVar->resolveIndirect()->toString();
            if ('' !== $name) {
                $body($name, $valueVar->resolveIndirect());
            }
        }
    }

    public static function callerVarIsSet(Variable $var): bool
    {
        // php-src zend_hash / symbol table: null CVs are still defined (#23567).
        return !$var->resolveIndirect()->isUndefined();
    }

    /**
     * get_defined_vars() snapshot — caller locals + file-scope auto globals (#3135).
     *
     * Compile-allocated CV slots start as TYPE_NULL (main-script globals) without an
     * assign — Zend's active symbol table only has assigned symbols (#24660).
     *
     * @param list<array{0: string, 1: int}> $namedSlots from Frame::block->eachNamedScopeSlot()
     * @param array<int, true> $initializedSlots Frame::$initializedSlots (assigned CVs)
     * @param array<string, Variable> $dynamicLocals
     * @param list<string> $autoGlobalNames
     */
    public static function buildDefinedVarsSnapshot(
        array $namedSlots,
        array $scopeBySlot,
        array $dynamicLocals,
        array $autoGlobalNames,
        ?callable $resolveAutoGlobal = null,
        array $initializedSlots = [],
    ): HashTable {
        $result = new HashTable();
        foreach ($namedSlots as [$name, $slot]) {
            if ('this' === $name || !isset($scopeBySlot[$slot])) {
                continue;
            }
            // php-src zend_get_defined_vars / EG(active_symbol_table): unassigned CVs omitted (#24660).
            if (!isset($initializedSlots[$slot])) {
                continue;
            }
            $value = $scopeBySlot[$slot];
            if (!self::callerVarIsSet($value)) {
                continue;
            }
            self::storeVarSnapshotAtStringKey($result, $name, $value);
        }

        $present = [];
        foreach ($result->iterateKeyed(true) as [$keyVar]) {
            $present[$keyVar->resolveIndirect()->toString()] = true;
        }
        foreach ($dynamicLocals as $name => $var) {
            if ('this' === $name || isset($present[$name]) || !self::callerVarIsSet($var)) {
                continue;
            }
            self::storeVarSnapshotAtStringKey($result, $name, $var);
        }

        if (null !== $resolveAutoGlobal) {
            foreach ($autoGlobalNames as $name) {
                if (isset($present[$name])) {
                    continue;
                }
                $source = $resolveAutoGlobal($name);
                if (null === $source || !self::callerVarIsSet($source)) {
                    continue;
                }
                self::storeVarSnapshotAtStringKey($result, $name, $source);
            }
        }

        return $result;
    }

    /**
     * get_declared_variables() — caller local names only (#4780).
     *
     * @param list<array{0: string, 1: int}> $namedSlots
     * @param array<string, Variable> $dynamicLocals
     */
    public static function buildDeclaredVariablesSnapshot(
        array $namedSlots,
        array $scopeBySlot,
        array $dynamicLocals,
    ): HashTable {
        $result = new HashTable();
        $index = 0;
        foreach ($namedSlots as [$name, $slot]) {
            if ('this' === $name || Superglobals::isSuperglobalName($name) || !isset($scopeBySlot[$slot])) {
                continue;
            }
            if (!self::callerVarIsSet($scopeBySlot[$slot])) {
                continue;
            }
            $entry = new Variable();
            $entry->string($name);
            $result->addIndex($index, $entry);
            ++$index;
        }

        foreach ($dynamicLocals as $name => $var) {
            if ('this' === $name || Superglobals::isSuperglobalName($name) || !self::callerVarIsSet($var)) {
                continue;
            }
            $entry = new Variable();
            $entry->string($name);
            $result->addIndex($index, $entry);
            ++$index;
        }

        return $result;
    }

    public static function emitCompactInvalidArgumentWarningFromVariable(int $argNum, Variable $var): void
    {
        compiler_language_warning(
            self::compactInvalidArgumentMessage($argNum, self::vmTypeLabel($var))
        );
    }

    private static function vmTypeLabel(Variable $var): string
    {
        // Match Zend compact() Warning actuals (false|true) (#30119).
        return EnumCaseSupport::typeNameForTypeErrorActual($var);
    }

    /**
     * get_defined_vars() / compact() — copy a live variable snapshot into $dest (#14507).
     *
     * @see VmScope::getDefinedVars() copyFrom path
     */
    public static function storeVarSnapshotAtStringKey(HashTable $dest, string $key, Variable $value): void
    {
        $resolved = $value->resolveIndirect();
        if ($resolved->isUndefined()) {
            return;
        }
        $copy = new Variable();
        $copy->copyFrom($resolved);
        $dest->update($key, $copy);
    }
}
