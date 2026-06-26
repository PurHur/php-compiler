<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;
/**
 * VM helpers for extract() / compact() (caller scope via Frame::parent).
 */
final class VmScope
{
    /** PHP EXTR_OVERWRITE — default when $flags omitted (php_array.h). */
    public const EXTR_OVERWRITE = StdlibConstants::EXTR_OVERWRITE;

    /** PHP EXTR_SKIP — do not overwrite variables that already hold a value (php_array.h). */
    public const EXTR_SKIP = StdlibConstants::EXTR_SKIP;

    public const EXTR_PREFIX_SAME = StdlibConstants::EXTR_PREFIX_SAME;

    public const EXTR_PREFIX_ALL = StdlibConstants::EXTR_PREFIX_ALL;

    public const EXTR_PREFIX_INVALID = StdlibConstants::EXTR_PREFIX_INVALID;

    public const EXTR_PREFIX_IF_EXISTS = StdlibConstants::EXTR_PREFIX_IF_EXISTS;

    public const EXTR_IF_EXISTS = StdlibConstants::EXTR_IF_EXISTS;

    public const EXTR_REFS = StdlibConstants::EXTR_REFS;

    public static function requireCaller(Frame $frame): Frame
    {
        if (null === $frame->parent || null === $frame->parent->block) {
            throw new \LogicException('extract() and compact() require an active caller frame');
        }

        return $frame->parent;
    }

    public static function slotForName(Frame $caller, string $name): ?int
    {
        return $caller->block->slotIndexForVariableName($name);
    }

    /** Resolve a caller local by compile-time slot or runtime dynamicLocals (#4826). */
    private static function callerVariable(Frame $caller, string $name): ?Variable
    {
        $slot = self::slotForName($caller, $name);
        if (null !== $slot) {
            return $caller->scope[$slot] ?? null;
        }
        if (null === $caller->block) {
            return null;
        }

        return $caller->block->findVariableByRuntimeName($name, $caller);
    }

    /** Writable caller local — allocates dynamicLocals when extract imports an unknown name (#4826). */
    private static function ensureCallerVariable(Frame $caller, string $name): Variable
    {
        $existing = self::callerVariable($caller, $name);
        if (null !== $existing) {
            return $existing;
        }
        if (null === $caller->block) {
            throw new \LogicException('extract() requires an active caller block');
        }

        return $caller->block->ensureVariableByRuntimeName($name, $caller);
    }

    public static function extract(Frame $frame): int
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('extract() requires one to three arguments in this compiler build');
        }
        $caller = self::requireCaller($frame);
        $ht = VmArray::requireArray($frame->calledArgs[0], 'extract');

        $flags = self::EXTR_OVERWRITE;
        if ($argc >= 2) {
            $flagsArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsArg->type) {
                throw new \LogicException('extract() flags must be an integer in this compiler build');
            }
            $flags = $flagsArg->toInt();
        }

        $refs = 0 !== ($flags & self::EXTR_REFS);
        $extractType = $flags & 0xFF;
        if ($extractType < self::EXTR_OVERWRITE || $extractType > self::EXTR_IF_EXISTS) {
            self::extractWarning($frame, 'Invalid extract type');

            return 0;
        }

        $prefix = null;
        if ($extractType > self::EXTR_SKIP && $extractType <= self::EXTR_PREFIX_IF_EXISTS) {
            if ($argc < 3) {
                self::extractWarning($frame, 'specified extract type requires the prefix parameter');

                return 0;
            }
            $prefixArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $prefixArg->type) {
                throw new \LogicException('extract() prefix must be a string in this compiler build');
            }
            $prefix = $prefixArg->toString();
            if ('' !== $prefix && !self::isValidVarName($prefix)) {
                self::extractWarning($frame, 'prefix is not a valid identifier');

                return 0;
            }
        }

        return self::extractIntoCaller($caller, $ht, $extractType, $refs, $prefix, $frame);
    }

    /**
     * php-src: ext/standard/array.c — php_extract / ZEND_HASH_FOREACH_KEY_VAL_IND.
     */
    private static function extractIntoCaller(
        Frame $caller,
        HashTable $table,
        int $extractType,
        bool $refs,
        ?string $prefix,
        Frame $builtinFrame,
    ): int {
        $imported = 0;
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyResolved = $keyVar->resolveIndirect();
            $stringKey = null;
            if (Variable::TYPE_STRING === $keyResolved->type) {
                $stringKey = $keyResolved->toString();
            } elseif (Variable::TYPE_INTEGER === $keyResolved->type) {
                if (self::EXTR_PREFIX_ALL !== $extractType && self::EXTR_PREFIX_INVALID !== $extractType) {
                    continue;
                }
                $stringKey = (string) $keyResolved->toInt();
            } else {
                continue;
            }

            if ('' === $stringKey) {
                continue;
            }

            $varExists = self::callerNameExists($caller, $stringKey);
            $finalName = self::resolveExtractFinalName($stringKey, $varExists, $extractType, $prefix);
            if (null === $finalName || !self::isValidVarName($finalName)) {
                continue;
            }

            if (self::EXTR_OVERWRITE === $extractType || self::EXTR_IF_EXISTS === $extractType) {
                if ('GLOBALS' === $finalName) {
                    continue;
                }
            }

            $target = self::ensureCallerVariable($caller, $finalName);
            if ($refs) {
                $target->indirect($valueVar);
            } else {
                $target->copyFrom($valueVar);
            }
            self::markCallerVariableInitialized($caller, $finalName, $builtinFrame);
            ++$imported;
        }

        return $imported;
    }

    /** php-src: php_prefix_varname — prefix and key joined by underscore. */
    public static function prefixVarName(string $prefix, string $key): string
    {
        return $prefix.'_'.$key;
    }

    /**
     * @see ext/standard/array.c switch (extract_type) in php_extract
     */
    private static function resolveExtractFinalName(
        string $key,
        bool $varExists,
        int $extractType,
        ?string $prefix,
    ): ?string {
        $finalName = null;

        switch ($extractType) {
            case self::EXTR_IF_EXISTS:
                if (!$varExists) {
                    return null;
                }
                // no break — fall through to EXTR_OVERWRITE

            case self::EXTR_OVERWRITE:
                return $key;

            case self::EXTR_PREFIX_IF_EXISTS:
                if ($varExists) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return null;

            case self::EXTR_PREFIX_SAME:
                if (!$varExists) {
                    $finalName = $key;
                }
                // no break — fall through to EXTR_PREFIX_ALL

            case self::EXTR_PREFIX_ALL:
                if (null === $finalName) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return $finalName;

            case self::EXTR_PREFIX_INVALID:
                if (!self::isValidVarName($key)) {
                    return self::prefixVarName($prefix ?? '', $key);
                }

                return $key;

            case self::EXTR_SKIP:
            default:
                if (!$varExists) {
                    return $key;
                }

                return null;
        }
    }

    private static function callerNameExists(Frame $caller, string $name): bool
    {
        $slot = self::slotForName($caller, $name);
        if (null !== $slot) {
            if (!isset($caller->scope[$slot])) {
                return false;
            }

            return isset($caller->initializedSlots[$slot]) || self::callerVarIsSet($caller->scope[$slot]);
        }
        $var = self::callerVariable($caller, $name);
        if (null === $var) {
            return false;
        }

        return !$var->resolveIndirect()->isUndefined();
    }

    private static function isValidVarName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return 1 === \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    private static function extractWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        [$file, $line] = self::compactCallSite($frame);
        $frame->vmContext->errors->triggerError(
            'extract(): '.$message,
            ErrorReporter::E_WARNING,
            $file,
            $frame->vmContext,
            $frame,
            $line
        );
    }

    /** Zend symbol-table import marks CVs initialized — no later undefined-variable warnings (#10590). */
    private static function markCallerVariableInitialized(Frame $caller, string $name, Frame $builtinFrame): void
    {
        $slot = self::slotForName($caller, $name);
        if (null !== $slot) {
            $caller->initializedSlots[$slot] = true;
            self::markGlobalEverAssignedForSlot($caller, $slot, $builtinFrame);
        }
    }

    /** Script-level locals alias globalVars — mark assigned like TYPE_ASSIGN (#10590). */
    private static function markGlobalEverAssignedForSlot(Frame $caller, int $slot, Frame $builtinFrame): void
    {
        $context = $caller->vmContext ?? $builtinFrame->vmContext;
        if (null === $context || !isset($caller->scope[$slot])) {
            return;
        }
        $globalName = $context->globalNameForStorage($caller->scope[$slot]);
        if (null !== $globalName) {
            $context->markGlobalEverAssigned($globalName);
        }
    }

    public static function compact(Frame $frame): HashTable
    {
        $caller = self::requireCaller($frame);
        $result = new HashTable();
        foreach ($frame->calledArgs as $argIndex => $arg) {
            foreach (self::collectCompactNames($frame, (int) $argIndex + 1, $arg->resolveIndirect()) as $name) {
                $value = self::resolveCompactVariable($frame, $caller, $name);
                if (null === $value) {
                    self::compactUndefinedVariableWarning($frame, $name);
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result->add($name, $copy);
            }
        }

        return $result;
    }

    private static function resolveCompactVariable(Frame $frame, Frame $caller, string $name): ?Variable
    {
        $value = self::callerVariable($caller, $name);
        if (null !== $value) {
            $resolved = $value->resolveIndirect();
            if (!$resolved->isUndefined()) {
                return $value;
            }
        }
        if (null !== $frame->vmContext) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($name);
            if ($frame->vmContext->globalsTableOffsetIsSet($key)) {
                return $frame->vmContext->ensureGlobal($name);
            }
        }
        if (!Superglobals::isSuperglobalName($name) || null === $frame->vmContext) {
            return null;
        }

        return $frame->vmContext->ensureSuperglobal($name);
    }

    /**
     * @return list<string>
     */
    private static function collectCompactNames(Frame $frame, int $argNum, Variable $var): array
    {
        if (Variable::TYPE_STRING === $var->type) {
            return [$var->toString()];
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $names = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $valueVar]) {
                $names = array_merge(
                    $names,
                    self::collectCompactNames($frame, $argNum, $valueVar->resolveIndirect())
                );
            }

            return $names;
        }

        self::compactInvalidArgumentWarning($frame, $argNum, $var);

        return [];
    }

    private static function compactInvalidArgumentWarning(Frame $frame, int $argNum, Variable $var): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $typeName = self::compactInvalidArgTypeName($var);
        [$file, $line] = self::compactCallSite($frame);
        $frame->vmContext->errors->triggerError(
            "compact(): Argument #{$argNum} must be string or array of strings, {$typeName} given",
            ErrorReporter::E_WARNING,
            $file,
            $frame->vmContext,
            $frame,
            $line
        );
    }

    private static function compactInvalidArgTypeName(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown type',
        };
    }

    private static function compactUndefinedVariableWarning(Frame $frame, string $name): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        [$file, $line] = self::compactCallSite($frame);
        $frame->vmContext->errors->triggerError(
            "compact(): Undefined variable \${$name}",
            ErrorReporter::E_WARNING,
            $file,
            $frame->vmContext,
            $frame,
            $line
        );
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function compactCallSite(Frame $frame): array
    {
        $caller = $frame->parent;
        if (null === $caller) {
            return [null, 0];
        }
        $file = '' !== $caller->scriptPath ? $caller->scriptPath : null;

        return [$file, $caller->callSiteLine];
    }

    private static function callerVarIsSet(Variable $var): bool
    {
        $v = $var->resolveIndirect();
        if ($v->isUndefined()) {
            return false;
        }

        return Variable::TYPE_NULL !== $v->type;
    }

    /** get_defined_vars() — snapshot of caller locals (php-src: zend_get_defined_vars). */
    public static function getDefinedVars(Frame $frame): HashTable
    {
        $caller = self::requireCaller($frame);
        $result = new HashTable();
        foreach ($caller->block->eachNamedScopeSlot() as [$name, $slot]) {
            if ('this' === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!isset($caller->scope[$slot])) {
                continue;
            }
            $value = $caller->scope[$slot];
            if (!self::callerVarIsSet($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $result->add($name, $copy);
        }

        return $result;
    }

    /** get_declared_variables() — caller local names only (php-src: php_get_defined_vars names). */
    public static function getDeclaredVariables(Frame $frame): HashTable
    {
        $caller = self::requireCaller($frame);
        $result = new HashTable();
        $index = 0;
        foreach ($caller->block->eachNamedScopeSlot() as [$name, $slot]) {
            if ('this' === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!isset($caller->scope[$slot])) {
                continue;
            }
            if (!self::callerVarIsSet($caller->scope[$slot])) {
                continue;
            }
            $entry = new Variable();
            $entry->string($name);
            $result->addIndex($index, $entry);
            ++$index;
        }

        return $result;
    }

}
