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
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('extract() requires one or two arguments in this compiler build');
        }
        $caller = self::requireCaller($frame);
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }
        $flags = self::EXTR_OVERWRITE;
        if (2 === \count($frame->calledArgs)) {
            $flagsArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsArg->type) {
                throw new \LogicException('extract() flags must be an integer in this compiler build');
            }
            $flags = $flagsArg->toInt();
        }

        return self::extractIntoCaller($caller, $array->toArray(), $flags, $frame);
    }

    private static function extractIntoCaller(Frame $caller, HashTable $table, int $flags, Frame $builtinFrame): int
    {
        $imported = 0;
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $name = $keyVar->toString();
            if (null !== $caller->block) {
                $slotHandled = false;
                foreach ($caller->block->eachNamedScopeSlot() as [$slotName, $slot]) {
                    if ($slotName !== $name) {
                        continue;
                    }
                    $slotHandled = true;
                    if (!isset($caller->scope[$slot])) {
                        $caller->scope[$slot] = new Variable();
                    }
                    if (self::EXTR_SKIP === ($flags & self::EXTR_SKIP) && self::callerVarIsSet($caller->scope[$slot])) {
                        continue;
                    }
                    $caller->scope[$slot]->copyFrom($valueVar);
                    $caller->initializedSlots[$slot] = true;
                    self::markGlobalEverAssignedForSlot($caller, $slot, $builtinFrame);
                    ++$imported;
                }
                if ($slotHandled) {
                    continue;
                }
            }
            $target = self::ensureCallerVariable($caller, $name);
            if (self::EXTR_SKIP === ($flags & self::EXTR_SKIP) && self::callerVarIsSet($target)) {
                continue;
            }
            $target->copyFrom($valueVar);
            self::markCallerVariableInitialized($caller, $name, $builtinFrame);
            ++$imported;
        }

        return $imported;
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
