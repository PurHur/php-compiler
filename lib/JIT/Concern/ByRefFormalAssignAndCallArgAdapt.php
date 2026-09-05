<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * By-ref formal assign + native call-arg adaptation (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code bindJitParamByReference}
 * through {@code ensureValueBoxLvalueForByRefPass} (~625 lines) so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c ZEND_SEND_REF / ZEND_RECV / zend_assign_to_variable —
 * move-only Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait ByRefFormalAssignAndCallArgAdapt
{
    private function bindJitParamByReference(
        Block $block,
        Operand $paramOperand,
        Variable $callerArg
    ): void {
        if (!$this->context->hasVariableOp($paramOperand)) {
            throw new \LogicException('By-reference parameter requires a bound operand');
        }
        $paramVar = $this->context->getVariableFromOp($paramOperand);
        JIT\ClosureHelper::bindCaptureSlotByReference($this->context, $paramVar, $callerArg);
        $this->context->setVariableOp($paramOperand, $paramVar);
        // php-cfg uses a fresh SSA var for `$x = …` inside the callee; bind the name so
        // assigns/reads share the aliased formal (#24162, Zend ZEND_RECV / SEND_REF).
        $name = JIT\OperandName::resolve($paramOperand);
        if (null !== $name && '' !== $name) {
            $this->context->bindVariableByName($name, $paramVar);
        }
        $this->syncJitParamVariableToSlotOperands($block, $paramOperand, $paramVar);
    }

    /** Rebind every scoped operand sharing a formal slot (#27624, e06_byref `$r = $v`). */
    private function syncJitParamVariableToSlotOperands(
        Block $block,
        Operand $paramOperand,
        Variable $paramVar
    ): void {
        $slot = $block->slotForOperand($paramOperand);
        if (null === $slot) {
            return;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) === $slot) {
                $this->context->setVariableOp($scopeOp, $paramVar);
            }
        }
    }

    /**
     * {@see TYPE_ASSIGN} into a by-ref formal: write through the LLVM {@see __value__*} edge
     * argument (ZEND_SEND_REF / zend_assign_to_variable), bypassing orphan SSA operands (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function emitAssignOperandWithByRefFormalFastPath(
        Block $block,
        Operand $destOp,
        Operand $rhsOperand,
        Variable $value,
        array $args,
        int $thisParamOffset,
        bool $force
    ): void {
        if (
            !$this->tryEmitByRefFormalValueBoxAssign(
                $block,
                $destOp,
                $rhsOperand,
                $value,
                $args,
                $thisParamOffset
            )
        ) {
            $this->assignOperand($destOp, $value, $force);
        }
    }

    /**
     * {@see TYPE_ASSIGN} into a by-ref formal: write through the LLVM {@see __value__*} edge
     * argument (ZEND_SEND_REF / zend_assign_to_variable), bypassing orphan SSA operands (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function tryEmitByRefFormalValueBoxAssign(
        Block $block,
        Operand $destOp,
        Operand $rhsOperand,
        Variable $value,
        array $args,
        int $thisParamOffset
    ): bool {
        if (null === $block->func || [] === $block->paramByRef) {
            return false;
        }
        $refIdx = $this->byRefFormalParamIndexForAssignDest($block, $destOp);
        if (null === $refIdx) {
            return false;
        }
        $formalRhs = $this->tryResolveFormalParamVariableForRhs($block, $rhsOperand);
        if (null !== $formalRhs) {
            $value = $formalRhs;
        } else {
            $rhsSlot = $block->slotForOperand($rhsOperand);
            if (null !== $rhsSlot) {
                foreach ($block->func->params as $param) {
                    if (
                        $block->slotForOperand($param->result) === $rhsSlot
                        && $this->context->hasVariableOp($param->result)
                    ) {
                        $value = $this->context->getVariableFromOp($param->result);
                        break;
                    }
                }
            } elseif ($this->context->hasVariableOp($rhsOperand)) {
                $value = $this->context->getVariableFromOp($rhsOperand);
            }
        }
        $destBinding = $this->resolveByRefFormalAssignDestBinding($block, $destOp, $args, $thisParamOffset, $refIdx);
        if (null === $destBinding) {
            return false;
        }
        [$destPtr, $destVar] = $destBinding;
        if ($this->tryEmitByRefFormalAssignFromCalleeFormal($block, $rhsOperand, $destPtr, $args, $thisParamOffset)) {
            // emitted from ABI formal edge
        } elseif ($this->tryEmitDirectByRefFormalValueBoxCopy($destPtr, $value)) {
            // emitted
        } elseif (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VARIABLE === $value->kind
            && '__value__' === $this->context->getStringFromType($value->value->typeOf())
        ) {
            JIT\JitValueBox::copyIntoPointer(
                $this->context,
                $destPtr,
                JIT\JitValueBox::pointer($this->context, $value->value)
            );
        } else {
            JIT\JitValueBox::assignToPointer($this->context, $destPtr, $value);
        }
        JIT\JitValueBox::publishAfterWrite($this->context, $destPtr);
        $this->context->setVariableOp($destOp, $destVar);
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($destName),
                $destVar
            );
        }
        JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $destVar);

        return true;
    }

    /**
     * `$r = $v` when RHS is an untyped formal still on the LLVM {@see __value__} edge (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function tryEmitByRefFormalAssignFromCalleeFormal(
        Block $block,
        Operand $rhsOperand,
        \PHPLLVM\Value $destPtr,
        array $args,
        int $thisParamOffset
    ): bool {
        $rhsSlot = $block->slotForOperand($rhsOperand);
        if (null === $rhsSlot) {
            return false;
        }
        foreach ($block->func->params as $idx => $param) {
            if ($block->slotForOperand($param->result) !== $rhsSlot) {
                continue;
            }
            $argIdx = $thisParamOffset + (int) $idx;
            if (!isset($args[$argIdx])) {
                return false;
            }
            $formal = $args[$argIdx];
            if (
                Variable::KIND_VALUE !== $formal->kind
                || Variable::TYPE_VALUE !== $formal->type
                || '__value__' !== $this->context->getStringFromType($formal->value->typeOf())
            ) {
                return false;
            }
            if (!JIT\BasicBlockHelper::unsealAndContinue($this->context)) {
                JIT\BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($this->context, 'byref_formal_abi_cont');
            }
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->builder->store($formal->value, $slot);
            $srcPtr = JIT\JitValueBox::pointer($this->context, $slot);
            $long = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $srcPtr
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $long
            );
            JIT\JitValueBox::publishAfterWrite($this->context, $destPtr);

            return true;
        }

        return false;
    }

    /**
     * Direct typed write into a by-ref formal edge — avoids copyBetweenPointers dispatch
     * picking the wrong source box when orphan SSA operands share a scope slot (#e06_byref).
     */
    private function tryEmitDirectByRefFormalValueBoxCopy(\PHPLLVM\Value $destPtr, Variable $value): bool
    {
        if (Variable::TYPE_NATIVE_LONG === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->helper->loadValue($value)
            );

            return true;
        }
        $srcPtr = null;
        if (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VALUE === $value->kind
            && '__value__*' === $this->context->getStringFromType($value->value->typeOf())
        ) {
            $srcPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $value->value);
        } elseif (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VARIABLE === $value->kind
        ) {
            $llvmTy = $this->context->getStringFromType($value->value->typeOf());
            if ('__value__*' === $llvmTy) {
                $srcPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $value->value);
            } elseif ('__value__' === $llvmTy) {
                $srcPtr = JIT\JitValueBox::pointer($this->context, $value->value);
            }
        }
        if (null === $srcPtr) {
            return false;
        }
        if (!JIT\BasicBlockHelper::unsealAndContinue($this->context)) {
            JIT\BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($this->context, 'byref_formal_assign_cont');
        }
        $map = $this->context->structFieldMap['__value__'];
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $kind = $this->context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isLong = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $longBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_long');
        $slowBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_slow');
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_done');
        $this->context->builder->branchIf($isLong, $longBlock, $slowBlock);
        $this->context->builder->positionAtEnd($longBlock);
        $long = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $srcPtr
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $destPtr,
            $long
        );
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($slowBlock);
        JIT\JitValueBox::assignToPointer($this->context, $destPtr, $value);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);

        return true;
    }

    /**
     * @param list<Variable> $args
     *
     * @return array{0: \PHPLLVM\Value, 1: Variable}|null
     */
    private function resolveByRefFormalAssignDestBinding(
        Block $block,
        Operand $destOp,
        array $args,
        int $thisParamOffset,
        int $refIdx
    ): ?array {
        $param = $block->func->params[$refIdx] ?? null;
        if (null !== $param && $this->context->hasVariableOp($param->result)) {
            $paramVar = $this->context->getVariableFromOp($param->result);
            if (null !== $paramVar->valueBoxAliasPtr) {
                return [
                    JIT\JitValueBox::normalizeValuePtr($this->context, $paramVar->valueBoxAliasPtr),
                    $paramVar,
                ];
            }
        }
        $argIdx = $thisParamOffset + $refIdx;
        if (isset($args[$argIdx])) {
            $argVar = $args[$argIdx];
            $destVar = $argVar;
            if (null !== $param && $this->context->hasVariableOp($param->result)) {
                $destVar = $this->context->getVariableFromOp($param->result);
            }

            return [
                JIT\JitValueBox::valuePtrFromVariable($this->context, $argVar),
                $destVar,
            ];
        }
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $boundName = $this->context->resolveRefAliasName($destName);
            if (isset($this->context->namedVariableBindings[$boundName])) {
                $bound = $this->context->namedVariableBindings[$boundName];
                if (null !== $bound->valueBoxAliasPtr) {
                    return [
                        JIT\JitValueBox::normalizeValuePtr($this->context, $bound->valueBoxAliasPtr),
                        $bound,
                    ];
                }
            }
        }
        $paramName = $block->paramNames[$refIdx] ?? null;
        if (null !== $paramName && '' !== $paramName) {
            $boundName = $this->context->resolveRefAliasName($paramName);
            if (isset($this->context->namedVariableBindings[$boundName])) {
                $bound = $this->context->namedVariableBindings[$boundName];
                if (null !== $bound->valueBoxAliasPtr) {
                    return [
                        JIT\JitValueBox::normalizeValuePtr($this->context, $bound->valueBoxAliasPtr),
                        $bound,
                    ];
                }
            }
        }

        return null;
    }

    private function byRefFormalParamIndexForAssignDest(Block $block, Operand $destOp): ?int
    {
        if (null === $block->func) {
            return null;
        }
        $destSlot = $block->slotForOperand($destOp);
        if (null !== $destSlot) {
            foreach ($block->paramByRef as $paramIdx => $_) {
                $param = $block->func->params[$paramIdx] ?? null;
                if (null === $param) {
                    continue;
                }
                if ($block->slotForOperand($param->result) === $destSlot) {
                    return (int) $paramIdx;
                }
            }
        }
        $destName = JIT\OperandName::resolve($destOp);
        if (null === $destName || '' === $destName) {
            return null;
        }
        foreach ($block->paramByRef as $paramIdx => $_) {
            $paramName = $block->paramNames[$paramIdx] ?? null;
            if (null === $paramName || $paramName !== $destName) {
                continue;
            }

            return (int) $paramIdx;
        }

        return null;
    }

    /**
     * php-cfg may use a distinct SSA operand for `$r = …` vs the param's {@see Param::result};
     * rebind before assign so {@see Variable::$valueBoxAliasPtr} is not lost (#e06_byref).
     */
    private function rebindAssignLvalueFromByRefFormalOrName(Operand $resultOp): bool
    {
        if ($this->context->hasVariableOp($resultOp)) {
            $existing = $this->context->getVariableFromOp($resultOp);
            if (
                null !== $existing->valueBoxAliasPtr
                || $existing->borrowedValueEntry
                || null !== $existing->foreachByRefPackedArm
                || $existing->assignRefLvalueAlias
            ) {
                return false;
            }
        }
        $block = $this->context->jitEnclosingBlock;
        if (null !== $block && null !== $block->func) {
            $slot = $block->slotForOperand($resultOp);
            if (null !== $slot) {
                foreach ($block->func->params as $paramIdx => $param) {
                    if (!isset($block->paramByRef[$paramIdx])) {
                        continue;
                    }
                    if ($block->slotForOperand($param->result) !== $slot) {
                        continue;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        continue;
                    }
                    $paramVar = $this->context->getVariableFromOp($param->result);
                    if (
                        null === $paramVar->valueBoxAliasPtr
                        && !$paramVar->borrowedValueEntry
                    ) {
                        continue;
                    }
                    $this->context->setVariableOp($resultOp, $paramVar);

                    return true;
                }
            }
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return false;
        }
        $boundName = $this->context->resolveRefAliasName($name);
        if (!isset($this->context->namedVariableBindings[$boundName])) {
            return false;
        }
        $bound = $this->context->namedVariableBindings[$boundName];
        if (
            null === $bound->valueBoxAliasPtr
            && !$bound->borrowedValueEntry
            && null === $bound->foreachByRefPackedArm
            && null === $bound->writableHt
            && null === $bound->objectPropertySlot
            && null === $bound->staticPropertyGlobal
            && !$bound->assignRefLvalueAlias
        ) {
            return false;
        }
        $this->context->setVariableOp($resultOp, $bound);

        return true;
    }

    /**
     * Recv a by-value {@see __value__} ABI formal via struct store, not copyBetweenPointers (#e06_byref).
     *
     * Sealed prologue BBs made the dispatch copy unreachable; `$r = $v` then copied null into
     * the caller's by-ref slot.
     */
    private function storeJitCalleeValueStructFormal(Operand $paramOperand, Variable $formalArg): bool
    {
        if (Variable::KIND_VALUE !== $formalArg->kind || Variable::TYPE_VALUE !== $formalArg->type) {
            return false;
        }
        if ('__value__' !== $this->context->getStringFromType($formalArg->value->typeOf())) {
            return false;
        }
        if (!$this->context->hasVariableOp($paramOperand)) {
            return false;
        }
        $dest = $this->context->getVariableFromOp($paramOperand);
        if ('__value__' !== $this->context->getStringFromType($dest->value->typeOf())) {
            return false;
        }
        $this->context->builder->store($formalArg->value, $dest->value);
        $dest->addref();
        JIT\UndefinedVariableHelper::markAssigned($this->context, $paramOperand, $dest);

        return true;
    }

    /**
     * @param list<Variable> $args
     * @param list<Operand> $operands
     *
     * @return list<Variable>
     */
    private function adaptByRefCallArgs(
        JIT\Call\Native $call,
        array $args,
        array $operands,
        Block $block
    ): array {
        if ([] === $call->paramByRefByArg) {
            return $args;
        }
        foreach ($call->paramByRefByArg as $idx => $_) {
            if (null !== $call->variadicArgIndex && $idx === $call->variadicArgIndex) {
                continue;
            }
            if (!isset($args[$idx])) {
                continue;
            }
            $operand = $operands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            $args[$idx] = $this->adaptNativeByRefCallArg($call, $block, $idx, $operand, $args[$idx]);
        }
        if (
            null !== $call->variadicArgIndex
            && isset($call->paramByRefByArg[$call->variadicArgIndex])
        ) {
            $start = $call->variadicArgIndex;
            $end = \count($args) - 1;
            if (null !== $call->namedArgsVariadicIndex) {
                $trailing = \count($call->paramNames) - $call->namedArgsVariadicIndex - 1;
                if ($trailing > 0) {
                    $end = \count($args) - $trailing - 1;
                }
            }
            for ($idx = $start; $idx <= $end; ++$idx) {
                if (!isset($args[$idx])) {
                    continue;
                }
                $operand = $operands[$idx] ?? null;
                if (null === $operand) {
                    continue;
                }
                $args[$idx] = $this->adaptNativeByRefCallArg($call, $block, $idx, $operand, $args[$idx]);
            }
        }

        return $args;
    }

    /**
     * User/method by-ref actual: named lvalue → alias; call/new return temp → Notice + temp box;
     * other non-variables → Error (#30027, zend_execute.c ZEND_SEND_VAR_NO_REF).
     */
    private function adaptNativeByRefCallArg(
        JIT\Call\Native $call,
        Block $block,
        int $idx,
        Operand $operand,
        Variable $arg
    ): Variable {
        $namedLocalSlot = $block->slotForOperand($operand);
        if (null !== $namedLocalSlot && $block->isNamedVariableSlot((int) $namedLocalSlot)) {
            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        if (JIT\JitReferencableCheck::isOperandReferenceable($operand, $arg)) {
            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        if (VM\ReferencableCheck::operandIsFuncCallReturn($operand, $block)) {
            JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
            $arg->nonVariableByRefTempAllowed = true;

            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        // Match Call\Native::receiverPrefix — Argument #N skips implicit $this (#30027).
        $receiverPrefix = (
            [] !== $call->paramNames
            && \count($call->argTypes) === \count($call->paramNames) + 1
        ) ? 1 : 0;
        $phpParamIdx = max(0, $idx - $receiverPrefix);
        JIT\JitReferencableCheck::emitByRefError(
            $this->context,
            $call->name,
            $phpParamIdx,
            $call->paramNames
        );

        return $arg;
    }

    /**
     * @param list<JIT\Variable>           $args
     * @param list<Operand|null>           $operands
     *
     * @return list<JIT\Variable>
     */
    private function foldSortFamilyFlagsArg(string $name, array $args, array $operands, Block $block): array
    {
        $lc = strtolower($name);
        if (!\in_array($lc, [
            'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
            // array_unique flags share SORT_*|SORT_FLAG_CASE folding (#29114).
            'array_unique',
        ], true)) {
            return $args;
        }
        if (2 !== \count($args) || !isset($operands[1])) {
            return $args;
        }
        $resolved = \PHPCompiler\ext\standard\VmInternalCompare::tryResolveJitSortFlags($this->context, $args[1])
            ?? \PHPCompiler\ext\standard\VmInternalCompare::tryResolveJitSortFlagsFromBlock(
                $this->context,
                $block,
                $operands[1]
            );
        if (null !== $resolved) {
            $args[1] = JIT\Variable::fromConstantInt($this->context, $resolved);
        }

        return $args;
    }

    private function ensureValueBoxLvalueForByRefPass(Operand $op, Variable $var): Variable
    {
        // Zend: cannot create references to/from string offsets (#29523 / #21910).
        if (JIT\StringOffsetHelper::isWritableCharOffsetLvalue($var, $this->context)) {
            JIT\StringOffsetHelper::emitRefError($this->context);
            $this->context->builder->call($this->context->lookupFunction('abort'));
            $this->context->builder->clearInsertionPosition();

            return $var;
        }
        // Promote the caller's lvalue in place. Copying into a fresh box left the
        // original native/script-global binding unchanged, so AOT saw the pre-call
        // value (or null on {main}) after return (#24162, Zend ZEND_SEND_REF).
        $promoted = JIT\ClosureHelper::referenceCapture($this->context, $var);
        $this->context->setVariableOp($op, $promoted);
        $name = JIT\OperandName::resolve($op);
        if (null !== $name) {
            $this->context->bindVariableByName($name, $promoted);
        }
        $block = $this->context->jitCurrentBlock;
        if (null !== $block) {
            $slot = $block->slotForOperand($op);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) === $slot) {
                        $this->context->setVariableOp($scopeOp, $promoted);
                    }
                }
            }
        }

        return $promoted;
    }
}
