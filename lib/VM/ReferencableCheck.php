<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Op\Expr\Cast\Object_ as ObjectCastExpr;
use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ErrorReporter;

/**
 * Whether a call argument may bind to an &-parameter (Zend zend_execute.c ZEND_SEND_REF).
 */
final class ReferencableCheck
{
    private const NON_VARIABLE_BY_REF_NOTICE = 'Only variables should be passed by reference';

    public const NON_VARIABLE_BY_REF_NOTICE_MESSAGE = self::NON_VARIABLE_BY_REF_NOTICE;

    /** Zend zend_assign_to_variable_reference — `$a =& non_variable` (#30015). */
    private const NON_VARIABLE_ASSIGN_REF_NOTICE = 'Only variables should be assigned by reference';

    public const NON_VARIABLE_ASSIGN_REF_NOTICE_MESSAGE = self::NON_VARIABLE_ASSIGN_REF_NOTICE;

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
        $variadicByRefIdx = self::variadicByRefParamIndex($calleeBlock);
        $variadicEndIdx = null;
        if (null !== $variadicByRefIdx) {
            $variadicEndIdx = self::variadicByRefEndArgIndex(
                $calleeBlock,
                $variadicByRefIdx,
                $thisArgOffset,
                $calledArgs
            );
        }
        foreach ($calleeBlock->paramByRef as $paramIdx => $_) {
            $idx = (int) $paramIdx;
            if (null !== $variadicByRefIdx && $idx === $variadicByRefIdx) {
                $start = $variadicByRefIdx + $thisArgOffset;
                $paramName = $calleeBlock->paramNames[$idx] ?? 'param'.$idx;
                for ($argIndex = $start; $argIndex <= $variadicEndIdx; ++$argIndex) {
                    if (!array_key_exists($argIndex, $calledArgs)) {
                        continue;
                    }
                    self::assertArgument($fn, $idx, $paramName, $calledArgs[$argIndex], $caller);
                }
                continue;
            }
            $argIndex = $idx + $thisArgOffset;
            if (!array_key_exists($argIndex, $calledArgs)) {
                continue;
            }
            $paramName = $calleeBlock->paramNames[$idx] ?? 'param'.$idx;
            self::assertArgument($fn, $idx, $paramName, $calledArgs[$argIndex], $caller);
        }
    }

    public static function variadicByRefParamIndex(Block $calleeBlock): ?int
    {
        $variadicIdx = $calleeBlock->variadicParamIndex;
        if (null === $variadicIdx || !isset($calleeBlock->paramByRef[$variadicIdx])) {
            return null;
        }

        return $variadicIdx;
    }

    /**
     * @param array<int, Variable> $calledArgs
     */
    public static function variadicByRefEndArgIndex(
        Block $calleeBlock,
        int $variadicParamIdx,
        int $thisArgOffset,
        array $calledArgs
    ): int {
        $paramCount = \count($calleeBlock->paramNames);
        $maxArgIdx = -1;
        foreach (array_keys($calledArgs) as $argKey) {
            if ($argKey > $maxArgIdx) {
                $maxArgIdx = (int) $argKey;
            }
        }
        $hasTrailingFixedAfterVariadic = $variadicParamIdx < $paramCount - 1;
        if ($hasTrailingFixedAfterVariadic) {
            $trailingCount = $paramCount - $variadicParamIdx - 1;
            $numProvided = $maxArgIdx + 1;
            $numToTrailing = min(
                $trailingCount,
                max(0, $numProvided - $variadicParamIdx - 1)
            );

            return $numProvided - $numToTrailing - 1;
        }

        return $maxArgIdx;
    }

    /**
     * Whether $argIndex is in the variadic by-reference tail for a user call.
     */
    public static function outgoingUserArgNeedsVariadicByRef(
        Block $calleeBlock,
        int $argIndex,
        int $thisArgOffset,
        int $numProvidedAfterSend
    ): bool {
        $variadicByRefIdx = self::variadicByRefParamIndex($calleeBlock);
        if (null === $variadicByRefIdx) {
            return false;
        }
        $start = $variadicByRefIdx + $thisArgOffset;
        if ($argIndex < $start) {
            return false;
        }
        $paramCount = \count($calleeBlock->paramNames);
        $hasTrailingFixedAfterVariadic = $variadicByRefIdx < $paramCount - 1;
        if (!$hasTrailingFixedAfterVariadic) {
            return true;
        }
        $trailingCount = $paramCount - $variadicByRefIdx - 1;
        $numToTrailing = min(
            $trailingCount,
            max(0, $numProvidedAfterSend - $variadicByRefIdx - 1)
        );
        $variadicEndIdx = $numProvidedAfterSend - $numToTrailing - 1;

        return $argIndex <= $variadicEndIdx;
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
                && self::defersByRefForNullStreamContextSetter($fn)
                && Variable::TYPE_NULL === $calledArgs[$paramIdx]->resolveIndirect()->type
            ) {
                // php-src ext/standard/streams.c — Z_PARAM_RESOURCE TypeError before by-ref bind.
                continue;
            }
            if (
                0 === $paramIdx
                && self::skipsByRefWhenNotArray($fn)
                && !self::isArrayOperand($calledArgs[$paramIdx])
            ) {
                // #13408 / #4333: null literal → by-ref Error before array type validation.
                if (Variable::TYPE_NULL === $calledArgs[$paramIdx]->resolveIndirect()->type) {
                    $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
                    self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
                } elseif (!self::isReferenceable($calledArgs[$paramIdx], $caller)) {
                    $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
                    if (
                        'array_splice' === strtolower($fn)
                        && self::isEphemeralObjectCastArg($calledArgs[$paramIdx], $caller)
                    ) {
                        self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
                    } elseif (self::isObjectOperand($calledArgs[$paramIdx])) {
                        // #15216 / #13435: ephemeral object operands — E_NOTICE then TypeError in builtin.
                        self::emitNonVariableByRefNotice($caller);
                    } else {
                        // #4881 / #4333: scalar literals (int, string, …) → catchable Error, no notice.
                        self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
                    }
                }
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
            if (
                0 === $paramIdx
                && self::allowsNonVariableObjectByRef($fn)
                && !self::isReferenceable($calledArgs[$paramIdx], $caller)
                && self::isArrayOrObjectOperand($calledArgs[$paramIdx])
            ) {
                if (self::isEphemeralObjectCastArg($calledArgs[$paramIdx], $caller)) {
                    $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
                    self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
                }
                // Inline array literals must Error before callback validation (#10819, #16259).
                if (self::isEphemeralArrayArg($calledArgs[$paramIdx], $caller)) {
                    $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
                    self::assertArgument($fn, $paramIdx, $paramName, $calledArgs[$paramIdx], $caller);
                }
                if (self::shouldEmitNonVariableObjectByRefNotice($calledArgs[$paramIdx], $caller)) {
                    self::emitNonVariableByRefNotice($caller);
                }
                continue;
            }
            // reset/end/next/prev: call/method return temps → E_NOTICE + operate on temporary
            // (zend_execute.c ZEND_SEND_VAR_NO_REF). Inline literals/casts/ternary stay Error (#25815).
            if (
                0 === $paramIdx
                && self::isArrayInternalPointerMutatorBuiltin($fn)
                && !self::isReferenceable($calledArgs[$paramIdx], $caller)
                && self::isFuncCallReturnTempArg($calledArgs[$paramIdx], $caller)
                && self::isArrayOrObjectOperand($calledArgs[$paramIdx])
            ) {
                self::emitNonVariableByRefNotice($caller);
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
        // Zend SEND_REF: string offsets Error before generic non-variable by-ref (#29523 / #21910).
        if (self::isStringOffsetRef($arg)) {
            throw new \Error(Variable::STRING_OFFSET_REF_ERROR);
        }
        if (self::isReferenceable($arg, $caller)) {
            return;
        }
        throw new \Error(\sprintf(
            '%s(): Argument #%d ($%s) could not be passed by reference',
            $fn,
            $paramIdx + 1,
            $paramName
        ));
    }

    /** True when $arg is (or peels to) a string-offset lvalue — not referenceable (#29523). */
    public static function isStringOffsetRef(Variable $arg): bool
    {
        return Variable::TYPE_STRING_OFFSET === $arg->resolveIndirect()->type;
    }

    /**
     * Read-only pointer builtins may use materialized array literals (current/key/pos).
     * Mutators (next/prev/reset/end) reject literals with by-ref Error (#10557, #10295, #16594)
     * but allow call/method return temps with E_NOTICE (#25815).
     * array_multisort() also accepts inline arrays (zend_compile.c ZEND_SEND_REF).
     * Also used when wiring hoisted pointer FuncCall siblings before var_export(..., true) (#13829, #16556).
     */
    public static function allowsEphemeralArrayLiteralByRef(string $fn): bool
    {
        $lc = strtolower($fn);

        // extract(array &$array) accepts temporary arrays in php-src (#23572).
        return self::isArrayInternalPointerReadBuiltin($fn)
            || 'array_multisort' === $lc
            || 'extract' === $lc;
    }

    /** current/key/pos — read-only internal pointer API (#4967, #11196). */
    public static function isArrayInternalPointerReadBuiltin(string $fn): bool
    {
        return \in_array(strtolower($fn), ['current', 'key', 'pos'], true);
    }

    /** next/prev/reset/end — mutating internal pointer API (#4967, #16594). */
    public static function isArrayInternalPointerMutatorBuiltin(string $fn): bool
    {
        return \in_array(strtolower($fn), ['next', 'prev', 'reset', 'end'], true);
    }

    /**
     * Scope slot written by FUNCCALL_EXEC_RETURN (func/method/new result) — not an lvalue (#25815).
     */
    public static function scopeSlotIsFuncCallReturn(Block $block, int $slot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && (int) $op->arg1 === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Non-variable call/method/new return temp used as by-ref actual (#25815, ZEND_SEND_VAR_NO_REF).
     */
    public static function isFuncCallReturnTempArg(Variable $arg, Frame $caller): bool
    {
        if ($arg->isIndirect()) {
            return false;
        }
        $slot = self::scopeSlotForVariable($caller, $arg);
        if (null === $slot || null === $caller->block) {
            return false;
        }
        if ($caller->block->isNamedVariableSlot($slot)) {
            return false;
        }

        return self::scopeSlotIsFuncCallReturn($caller->block, $slot);
    }

    /**
     * Compile-time: operand is a FuncCall/MethodCall/StaticCall/New_ result temporary (#25815).
     */
    public static function operandIsFuncCallReturn(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $current = $operand;
        while ($current instanceof Temporary && null !== $current->original) {
            $current = $current->original;
        }
        foreach ($current->usages as $usage) {
            if (
                (
                    $usage instanceof \PHPCfg\Op\Expr\FuncCall
                    || $usage instanceof \PHPCfg\Op\Expr\NsFuncCall
                    || $usage instanceof \PHPCfg\Op\Expr\MethodCall
                    || $usage instanceof \PHPCfg\Op\Expr\StaticCall
                    || $usage instanceof \PHPCfg\Op\Expr\New_
                )
                && $usage->result === $current
            ) {
                return true;
            }
        }
        if (null !== $block) {
            $slot = $block->slotForOperand($operand);
            if (null !== $slot) {
                if ($block->isNamedVariableSlot((int) $slot)) {
                    return false;
                }

                return self::scopeSlotIsFuncCallReturn($block, (int) $slot);
            }
        }

        return false;
    }

    /** reset/current/key/… — ext/standard/array.c internal pointer API (#4967, #11196). */
    public static function isArrayInternalPointerBuiltin(string $fn): bool
    {
        return self::isArrayInternalPointerReadBuiltin($fn)
            || self::isArrayInternalPointerMutatorBuiltin($fn);
    }

    /**
     * Array sort mutators — non-array operands get TypeError in the builtin, not by-ref Error (#12675).
     *
     * @return list<string>
     */
    public static function arraySortMutatorFunctions(): array
    {
        return [
            'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
            'usort', 'uasort', 'uksort', 'natsort', 'natcasesort',
            'shuffle',
        ];
    }

    /**
     * array_pop/shift/unshift — non-array operands get TypeError in the builtin (#15216).
     *
     * @return list<string>
     */
    public static function arrayStackMutatorFunctions(): array
    {
        return ['array_pop', 'array_push', 'array_shift', 'array_unshift'];
    }

    public static function skipsByRefWhenNotArray(string $fn): bool
    {
        $lc = strtolower($fn);

        return \in_array($lc, self::arraySortMutatorFunctions(), true)
            || \in_array($lc, self::arrayStackMutatorFunctions(), true)
            || 'array_splice' === $lc;
    }

    /**
     * stream_context_set_* mutators — null context TypeError in VmStreamContext::requireRepresentation (#19213).
     *
     * @return list<string>
     */
    public static function streamContextSetterFunctions(): array
    {
        return [
            'stream_context_set_option',
            'stream_context_set_options',
            'stream_context_set_params',
        ];
    }

    public static function defersByRefForNullStreamContextSetter(string $fn): bool
    {
        return \in_array(strtolower($fn), self::streamContextSetterFunctions(), true);
    }

    /** array_walk* accepts object operands — empty non-lvalue objects get E_NOTICE only (ext/standard/array.c, #13237). */
    public static function allowsNonVariableObjectByRef(string $fn): bool
    {
        return \in_array(strtolower($fn), ['array_walk', 'array_walk_recursive'], true);
    }

    /** Runtime: notice only for ephemeral `new` objects, not inline (object) casts (#13237, #15948). */
    public static function shouldEmitNonVariableObjectByRefNotice(Variable $arg, Frame $caller, ?Operand $operand = null): bool
    {
        if (self::isEphemeralObjectCastArg($arg, $caller)) {
            return false;
        }

        return true;
    }

    /** Compile-time: skip notice for inline (object) casts — emit by-ref Error instead (#15948). */
    public static function shouldEmitNonVariableObjectByRefNoticeAtCompileTime(?Operand $operand, ?Block $block = null): bool
    {
        if (self::operandIsObjectCast($operand, $block)) {
            return false;
        }

        return true;
    }

    /** Inline (object) cast operand — by-ref Error on PHP 8.2+ (#15948, ext/standard/array.c). */
    public static function isEphemeralObjectCastArg(Variable $arg, Frame $caller): bool
    {
        if ($arg->isIndirect()) {
            return false;
        }
        $resolved = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
        $slot = self::scopeSlotForVariable($caller, $arg);
        if (null === $slot || null === $caller->block) {
            return false;
        }
        // Named CV assigned from (object)[...] is a real lvalue — not an inline cast (#17989).
        if ($caller->block->isNamedVariableSlot($slot)) {
            return false;
        }

        return self::scopeSlotIsObjectCastResult($caller->block, $slot);
    }

    public static function scopeSlotIsObjectCastResult(Block $block, int $slot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_OBJECT === $op->type && (int) $op->arg1 === $slot) {
                return true;
            }
        }

        return false;
    }

    public static function operandIsObjectCast(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $current = $operand;
        while ($current instanceof Temporary && null !== $current->original) {
            $current = $current->original;
        }
        foreach ($current->usages as $usage) {
            if ($usage instanceof ObjectCastExpr && $usage->result === $current) {
                return true;
            }
        }
        if (null !== $block) {
            $slot = $block->slotForOperand($operand);
            if (null !== $slot) {
                if ($block->isNamedVariableSlot($slot)) {
                    return false;
                }

                return self::scopeSlotIsObjectCastResult($block, $slot);
            }
        }

        return false;
    }

    /** Operand is array or object — other types get TypeError in the builtin (#11984). */
    private static function isArrayOrObjectOperand(Variable $arg): bool
    {
        $resolved = $arg->resolveIndirect();

        return Variable::TYPE_ARRAY === $resolved->type
            || Variable::TYPE_OBJECT === $resolved->type;
    }

    private static function isArrayOperand(Variable $arg): bool
    {
        return Variable::TYPE_ARRAY === $arg->resolveIndirect()->type;
    }

    private static function isObjectOperand(Variable $arg): bool
    {
        return Variable::TYPE_OBJECT === $arg->resolveIndirect()->type;
    }

    private static function emitNonVariableByRefNotice(Frame $caller): void
    {
        $ctx = self::resolveVmContext($caller);
        if (null === $ctx) {
            return;
        }
        $ctx->errors->triggerError(
            self::NON_VARIABLE_BY_REF_NOTICE,
            ErrorReporter::E_NOTICE,
            '' !== $caller->scriptPath ? $caller->scriptPath : null,
            $ctx,
            $caller,
            $caller->callSiteLine
        );
    }

    /**
     * Zend: `$a =& f()` / `$a =& $obj->m()` when the RHS is not a variable — E_NOTICE then
     * value-assign (zend_assign_to_variable_reference, #30015).
     */
    public static function emitNonVariableAssignRefNotice(Frame $caller): void
    {
        $ctx = self::resolveVmContext($caller);
        if (null === $ctx) {
            return;
        }
        $ctx->errors->triggerError(
            self::NON_VARIABLE_ASSIGN_REF_NOTICE,
            ErrorReporter::E_NOTICE,
            '' !== $caller->scriptPath ? $caller->scriptPath : null,
            $ctx,
            $caller,
            $caller->callSiteLine
        );
    }

    private static function resolveVmContext(Frame $caller): ?Context
    {
        for ($frame = $caller; null !== $frame; $frame = $frame->parent) {
            if (null !== $frame->vmContext) {
                return $frame->vmContext;
            }
        }

        return null;
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
        // Zend: string offsets are never referenceable (zend_execute.c SEND_REF / ASSIGN_REF).
        // Indirect FETCH_DIM_W temps peel to TYPE_STRING_OFFSET — reject before isIndirect (#29523).
        if (self::isStringOffsetRef($arg)) {
            return false;
        }
        if ($arg->isIndirect()) {
            return true;
        }
        $resolved = $arg->resolveIndirect();
        if (null !== $resolved->objectPropertyOwner) {
            return true;
        }
        if (
            Variable::TYPE_ARRAYACCESS_OFFSET === $resolved->type
            || Variable::TYPE_PROPERTY_HOOK_REF === $resolved->type
        ) {
            return true;
        }
        $slot = self::scopeSlotForVariable($caller, $arg);
        if (null === $slot) {
            return false;
        }
        // Assign-result / named CV slots are referenceable even without scope operand names (#12690).
        if ($caller->block->isNamedVariableSlot($slot)) {
            return true;
        }
        $operand = $caller->block->operandForScopeSlot($slot);
        if (null !== $operand && null !== Block::resolveVariableName($operand)) {
            return true;
        }
        if (isset($caller->block->constants[$slot])) {
            return false;
        }
        if (null === $operand) {
            return false;
        }

        return false;
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
