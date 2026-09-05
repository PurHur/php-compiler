<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM;

/**
 * Value-box coalesce arg-send, nested-JIT string params, dim-write orphan sync,
 * and concat-flatten helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code materializeCoalesceMergeSlotArgSend}
 * through {@code appendConcatLeafToNativeString} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_operators.c (concat / string append), Zend/zend_execute.c
 * (ASSIGN_DIM orphan value), Zend/zend_API.c (zpp string separate for nested call) —
 * move-only Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait ValueBoxCoalesceAndConcatHelpers
{
    private function materializeCoalesceMergeSlotArgSend(Block $block, Operand $sendOperand): Variable
    {
        if (!$this->context->hasVariableOp($sendOperand)) {
            $func = $this->context->builder->getInsertBlock()->getParent();
            $llvmBlock = $this->context->builder->getInsertBlock();
            $this->context->makeVariableFromOp($func, $llvmBlock, $block, $sendOperand);
        }
        $slotVar = $this->context->getVariableFromOp($sendOperand);
        if (Variable::TYPE_VALUE !== $slotVar->type) {
            $slotVar->isNullConstant = false;
            $slotVar->compileTimeString = null;
            $slotVar->compileTimeFloat = null;
            $slotVar->compileTimeConstantName = null;
            $slotVar->compileTimeEnumCase = null;

            return $slotVar;
        }
        // Always copy into a stack __value__ — AOT {main} named locals are script globals
        // (KIND_VALUE + __value__**). Returning them as-is let echo bitcast the global (#24009).
        $srcPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $slotVar);
        JIT\JitValueBox::publishAfterWrite($this->context, $srcPtr);
        $destSlot = JIT\JitValueBox::alloc($this->context);
        JIT\JitValueBox::copyFromPointer($this->context, $destSlot, $srcPtr);
        JIT\JitValueBox::publishAfterWrite(
            $this->context,
            JIT\JitValueBox::pointer($this->context, $destSlot)
        );

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
    }

    private function prepareNestedJitCalleeParamArgument(Variable $arg): Variable
    {
        if (!JIT\NestedJitCompileScope::isActive() || Variable::TYPE_STRING !== $arg->type) {
            return $arg;
        }
        if (Variable::KIND_VALUE !== $arg->kind) {
            $materialized = JIT\JitStringArg::lowerDominating(
                $this->context,
                $arg,
                'nested JIT string parameter'
            );

            return new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $materialized
            );
        }
        $llvmTy = $this->context->getStringFromType($arg->value->typeOf());
        if ('__string__*' !== $llvmTy) {
            return $arg;
        }
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__string__*')
        );
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $arg->value
        );
        $this->context->builder->store($owned, $slot);

        return new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $this->context->builder->load($slot)
        );
    }

    /**
     * prepareIndexWrite / prepareStringKeyWrite allocate an orphan __value__ box plus HT
     * write markers. Assigns commit into the HT (#21947); keep the orphan box in sync so
     * later reads of the same Variable (chained `$r = $a[i] = v`, array-literal elements)
     * observe the expression value rather than an empty box (#24055).
     */
    private function syncDimWriteOrphanValueBox(Variable $dimLvalue, Variable $value): void
    {
        if (Variable::TYPE_VALUE !== $dimLvalue->type || Variable::KIND_VARIABLE !== $dimLvalue->kind) {
            return;
        }
        $orphanPtr = JIT\JitValueBox::pointer($this->context, $dimLvalue->value);
        JIT\JitValueBox::assignToPointer($this->context, $orphanPtr, $value);
        JIT\JitValueBox::publishAfterWrite($this->context, $orphanPtr);
    }

    private function valueBoxPointer(Variable $value): PHPLLVM\Value
    {
        return JIT\JitValueBox::valuePtrFromVariable($this->context, $value);
    }

    private function unboxValueToNativeDouble(Variable $value): PHPLLVM\Value
    {
        $valuePtr = $this->valueBoxPointer($value);
        $map = $this->context->structFieldMap['__value__'];
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $doubleTy = $this->context->getTypeFromString('double');
        $isDouble = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isLong = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $readDouble = $this->context->builder->call(
            $this->context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $fromLong = $this->context->builder->siToFp($readLong, $doubleTy);

        return $this->context->builder->select(
            $isDouble,
            $readDouble,
            $this->context->builder->select($isLong, $fromLong, $doubleTy->constReal(0.0))
        );
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function consumeConcatPendingLeaves(\PHPCfg\Operand $op): array
    {
        $id = spl_object_id($op);
        if (isset($this->concatPendingLeaves[$id])) {
            $leaves = $this->concatPendingLeaves[$id];
            unset($this->concatPendingLeaves[$id]);

            return $leaves;
        }

        return [$op];
    }

    private function isSingleUseConcatChainTemp(
        Block $block,
        \PHPCfg\Operand $resultOp,
        int $resultSlot,
        int $fromIndex
    ): bool {
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            return false;
        }
        $useCount = 0;
        $n = \count($block->opCodes);
        for ($j = $fromIndex + 1; $j < $n; ++$j) {
            $other = $block->opCodes[$j];
            $reads = false;
            if (null !== $other->arg2 && (int) $other->arg2 === $resultSlot) {
                $reads = true;
            }
            if (null !== $other->arg3 && (int) $other->arg3 === $resultSlot) {
                $reads = true;
            }
            if (!$reads) {
                continue;
            }
            ++$useCount;
            if (OpCode::TYPE_CONCAT !== $other->type) {
                return false;
            }
        }

        return 1 === $useCount;
    }

    private function ensureNativeStringSlotForConcatFlatten(
        PHPLLVM\Value $func,
        Block $block,
        OpCode $op,
        \PHPCfg\Operand $destOp,
        Variable $result
    ): ?Variable {
        if (
            Variable::TYPE_STRING === $result->type
            && Variable::KIND_VARIABLE === $result->kind
        ) {
            $destSlotTy = null !== $result->value
                ? $this->context->getStringFromType($result->value->typeOf())
                : '';
            if ('__string__**' === $destSlotTy || 'ptr' === $destSlotTy) {
                return $result;
            }
        }
        if (
            Variable::TYPE_VALUE === $result->type
            || JIT\JitValueBox::isValueOperand($result)
            || (
                Variable::TYPE_STRING === $result->type
                && Variable::KIND_VALUE === $result->kind
            )
        ) {
            $destSlot = JIT\BasicBlockHelper::entryAllocaForFunction(
                $this->context,
                $func,
                $this->context->getTypeFromString('__string__*')
            );
            $promoted = new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VARIABLE,
                $destSlot
            );
            JIT\BasicBlockHelper::storeAtFunctionEntry(
                $this->context,
                $func,
                $this->context->type->string->pointer->constNull(),
                $destSlot
            );
            if (
                Variable::TYPE_VALUE === $result->type
                || JIT\JitValueBox::isValueOperand($result)
            ) {
                $this->seedNativeStringSlotFromValueBox($result, $destSlot);
            } elseif (null !== $result->value && Variable::KIND_VALUE === $result->kind) {
                JIT\BasicBlockHelper::storeAtFunctionEntry(
                    $this->context,
                    $func,
                    $result->value,
                    $destSlot
                );
                $promoted->addref();
            }
            $this->context->setVariableOp($destOp, $promoted);
            $this->bindPromotedStringConcatDest($block, $destOp, $promoted);

            return $promoted;
        }

        return null;
    }

    private function appendConcatLeafToNativeString(
        Variable $dest,
        \PHPCfg\Operand $leafOp,
        Block $block
    ): void {
        if ($leafOp instanceof Operand\Literal && \is_string($leafOp->value)) {
            if ('' === $leafOp->value) {
                return;
            }
            $lit = new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $this->context->builder->load(
                    $this->context->constantStringFromString($leafOp->value)
                )
            );
            $lit->compileTimeString = $leafOp->value;
            $this->context->type->string->appendInPlace($dest, $lit);

            return;
        }
        if (!$this->context->hasVariableOp($leafOp)) {
            // Literal ints / unresolved — coerce via makeVariable if possible.
            if ($leafOp instanceof Operand\Literal && \is_int($leafOp->value)) {
                $i64 = $this->context->getTypeFromString('int64');
                $this->context->type->string->appendInPlaceLong(
                    $dest,
                    $i64->constInt((int) $leafOp->value, true)
                );

                return;
            }
            $this->context->makeVariableFromOp(
                JIT\BasicBlockHelper::parentFunction($this->context),
                $this->context->builder->getInsertBlock(),
                $block,
                $leafOp
            );
        }
        $leaf = $this->context->getVariableFromOp($leafOp);
        if (Variable::TYPE_NATIVE_LONG === $leaf->type
            && JIT\IncDecResourceProvenance::cannotBeResourceForString($leafOp)
        ) {
            $this->context->type->string->appendInPlaceLong(
                $dest,
                $this->context->helper->loadValue($leaf)
            );

            return;
        }
        $coerced = JIT\JitNativeString::coerce($this->context, $leaf, $leafOp);
        $this->context->type->string->appendInPlace($dest, $coerced);
    }
}
