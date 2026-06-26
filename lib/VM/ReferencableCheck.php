<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * Whether a call argument may bind to an &-parameter (Zend zend_execute.c ZEND_SEND_REF).
 */
final class ReferencableCheck
{
    /**
     * @param list<Variable> $calledArgs
     */
    public static function assertOutgoingCallArgs(Func $call, Frame $caller, array $calledArgs): void
    {
        if ($call instanceof Func\PHP) {
            self::assertUserFunctionArgs($call->getName(), $call->block, $calledArgs, $caller);

            return;
        }
        if ($call instanceof Func\Internal) {
            // VmClassMethod handlers share names with global builtins (e.g. Generator::current)
            // but arg0 is $this, not a by-ref array parameter (#10610).
            if ($call instanceof VmClassMethod) {
                return;
            }
            self::assertInternalFunctionArgs($call->getName(), $calledArgs, $caller);
        }
    }

    /**
     * @param list<Variable> $calledArgs
     */
    private static function assertUserFunctionArgs(
        string $fn,
        Block $calleeBlock,
        array $calledArgs,
        Frame $caller
    ): void {
        if ([] === $calleeBlock->paramByRef) {
            return;
        }
        $thisArgOffset = 0;
        if (
            null !== $calleeBlock->func
            && null !== $calleeBlock->func->class
            && !(($calleeBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
        ) {
            $thisArgOffset = 1;
        }
        foreach ($calleeBlock->paramByRef as $paramIdx => $_) {
            $idx = (int) $paramIdx;
            $argIndex = $idx + $thisArgOffset;
            if (!array_key_exists($argIndex, $calledArgs)) {
                continue;
            }
            $paramName = $calleeBlock->paramNames[$idx] ?? 'param'.$idx;
            self::assertArgument($fn, $idx, $paramName, $calledArgs[$argIndex], $caller);
        }
    }

    /**
     * @param list<Variable> $calledArgs
     */
    private static function assertInternalFunctionArgs(string $fn, array $calledArgs, Frame $caller): void
    {
        $indices = BuiltinByRefParams::forFunction($fn);
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($fn);
        if ([] === $indices && null === $variadicFrom) {
            return;
        }
        $paramNames = BuiltinParamNames::forFunction($fn) ?? [];
        foreach ($indices as $paramIdx) {
            if (!array_key_exists($paramIdx, $calledArgs)) {
                continue;
            }
            if (
                0 === $paramIdx
                && self::allowsEphemeralArrayLiteralByRef($fn)
                && (
                    self::isEphemeralArrayArg($calledArgs[$paramIdx], $caller)
                    || !self::isArrayOrObjectOperand($calledArgs[$paramIdx])
                )
            ) {
                continue;
            }
            if (!BuiltinByRefParams::isByRefArg($fn, $paramIdx, $calledArgs[$paramIdx] ?? null)) {
                continue;
            }
            $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
            self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
        }
        if (null === $variadicFrom) {
            return;
        }
        $n = \count($calledArgs);
        for ($paramIdx = $variadicFrom; $paramIdx < $n; ++$paramIdx) {
            if (!isset($calledArgs[$paramIdx])) {
                continue;
            }
            if (!BuiltinByRefParams::isByRefArg($fn, $paramIdx, $calledArgs[$paramIdx])) {
                continue;
            }
            if (
                self::allowsEphemeralArrayLiteralByRef($fn)
                && self::isEphemeralArrayArg($calledArgs[$paramIdx], $caller)
            ) {
                continue;
            }
            $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
            self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
        }
    }

    private static function assertArgument(
        string $fn,
        int $paramIdx,
        string $paramName,
        Variable $arg,
        Frame $caller
    ): void {
        if (self::isReferenceable($arg, $caller)) {
            return;
        }
        throw new \Error(\sprintf(
            '%s(): Argument #%d ($%s) cannot be passed by reference',
            $fn,
            $paramIdx + 1,
            $paramName
        ));
    }

    /**
     * Zend accepts inline array literals for current()/key() and array_multisort() array operands
     * (zend_compile.c ZEND_SEND_REF temp materialization).
     */
    public static function allowsEphemeralArrayLiteralByRef(string $fn): bool
    {
        return \in_array(strtolower($fn), ['current', 'key', 'array_multisort'], true);
    }

    /** Operand is array or object — other types get TypeError in the builtin (#11984). */
    private static function isArrayOrObjectOperand(Variable $arg): bool
    {
        $resolved = $arg->resolveIndirect();

        return Variable::TYPE_ARRAY === $resolved->type
            || Variable::TYPE_OBJECT === $resolved->type;
    }

    /** Inline array literal operand — not an lvalue, but allowed for read-only pointer builtins (#10654). */
    public static function isEphemeralArrayArg(Variable $arg, Frame $caller): bool
    {
        if ($arg->isIndirect()) {
            return false;
        }
        $resolved = $arg->resolveIndirect();
        if (null !== $resolved->objectPropertyOwner) {
            return false;
        }
        if (
            Variable::TYPE_STRING_OFFSET === $resolved->type
            || Variable::TYPE_ARRAYACCESS_OFFSET === $resolved->type
            || Variable::TYPE_PROPERTY_HOOK_REF === $resolved->type
        ) {
            return false;
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            return false;
        }

        return !self::isReferenceable($arg, $caller);
    }

    public static function isReferenceable(Variable $arg, Frame $caller): bool
    {
        if ($arg->isIndirect()) {
            return true;
        }
        $resolved = $arg->resolveIndirect();
        if (null !== $resolved->objectPropertyOwner) {
            return true;
        }
        if (
            Variable::TYPE_STRING_OFFSET === $resolved->type
            || Variable::TYPE_ARRAYACCESS_OFFSET === $resolved->type
            || Variable::TYPE_PROPERTY_HOOK_REF === $resolved->type
        ) {
            return true;
        }
        $slot = self::scopeSlotForVariable($caller, $arg);
        if (null === $slot) {
            return false;
        }
        if (isset($caller->block->constants[$slot])) {
            // Named locals may share an initializer constant; still allow by-ref (#5593, #6689, #9700).
            if ($caller->block->isNamedVariableSlot($slot)) {
                return true;
            }

            return false;
        }
        $operand = $caller->block->operandForScopeSlot($slot);
        if (null === $operand) {
            return false;
        }

        return null !== Block::resolveVariableName($operand);
    }

    private static function scopeSlotForVariable(Frame $frame, Variable $var): ?int
    {
        foreach ($frame->scope as $slot => $v) {
            if ($v === $var) {
                return (int) $slot;
            }
        }

        return null;
    }
}
