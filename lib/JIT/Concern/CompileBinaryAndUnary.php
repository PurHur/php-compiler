<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Binary and unary arithmetic / comparison opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_MUL}/{@code PLUS}/{@code MINUS}
 * (incl. string inc/dec assign-op path), {@code DIV}/{@code MODULO}/bitwise/shift/
 * relational/{@code IDENTICAL}, {@code EQUAL}/{@code SPACESHIP}/{@code LOGICAL_XOR},
 * and {@code UNARY_MINUS}/{@code UNARY_PLUS}/{@code BITWISE_NOT}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_ADD / ZEND_SUB / ZEND_MUL / ZEND_DIV / ZEND_MOD /
 * ZEND_SL / ZEND_SR / ZEND_BW_* / ZEND_IS_* / ZEND_BW_NOT / ZEND_UNARY_*),
 * Zend/zend_operators.c — move-only Concern extract; no new C ABI.
 */
trait CompileBinaryAndUnary
{
    private function compileBinaryAndUnaryOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
                if (null === $op->arg3) {
                    break;
                }
                if ($op->isIncDec && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)) {
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $left = $this->context->getVariableFromOp($this->binaryOpLeftOperand($block, $op));
                    $right = $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, 'inc/dec right'));
                    $resultOp = $this->operandAt($block, $op->arg1, 'inc/dec result');
                    $literal = \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($left) ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($right);
                    if (null !== $literal) {
                        $vm = new \PHPCompiler\VM\Variable();
                        $vm->string($literal);
                        if (OpCode::TYPE_PLUS === $op->type) {
                            // php-src increment_string(): empty / non-alnum → E_DEPRECATED (#29658).
                            $this->emitStringIncrementDeprecationsIfNeeded($literal);
                            $vm->applyIncrement();
                        } else {
                            // php-src decrement_function() string path (#29088, #29658).
                            $this->emitStringDecrementDeprecationsIfNeeded($literal);
                            $vm->applyDecrement();
                        }
                        $this->assignOperand($resultOp, $this->jitVariableFromVmConstant($vm), true);
                        break;
                    }
                }
                // fall through
            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
            case OpCode::TYPE_BITWISE_AND:
            case OpCode::TYPE_BITWISE_OR:
            case OpCode::TYPE_BITWISE_XOR:
            case OpCode::TYPE_SHIFT_LEFT:
            case OpCode::TYPE_SHIFT_RIGHT:
            case OpCode::TYPE_GREATER_OR_EQUAL:
            case OpCode::TYPE_SMALLER_OR_EQUAL:
            case OpCode::TYPE_GREATER:
            case OpCode::TYPE_SMALLER:
            case OpCode::TYPE_IDENTICAL:
            case OpCode::TYPE_NOT_IDENTICAL:
                if (null === $op->arg3) {
                    break;
                }
                $this->maybeRefreshIncludeBindingsBeforeUse();
                $binLeftOp = $this->binaryOpLeftOperand($block, $op);
                $binRightOp = $this->operandAt($block, $op->arg3, \PHPCompiler\opcode_type_name($op->type).' right');
                $binDestOp = $this->operandAt($block, $op->arg1, \PHPCompiler\opcode_type_name($op->type).' result');
                $binLeft = $this->variableFromOpForRuntimeRead($binLeftOp);
                if (
                    \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($binLeft, $this->context)
                    || (
                        $this->context->hasVariableOp($binDestOp)
                        && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue(
                            $this->context->getVariableFromOp($binDestOp),
                            $this->context
                        )
                    )
                ) {
                    // Zend: Cannot use assign-op operators with string offsets (#22897).
                    \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                    break;
                }
                // FETCH_DIM_W assign-op ($a[i] += n): hydrate orphan box before the read (#32789).
                if (null !== $binLeft->writableHt) {
                    \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $binLeft);
                }
                $this->assignOperand(
                    $binDestOp,
                    $this->compileBinaryOp(
                        $op,
                        $binLeft,
                        $this->variableFromOpForRuntimeRead($binRightOp)
                    )
                );
                if (
                    OpCode::TYPE_IDENTICAL === $op->type
                    || OpCode::TYPE_NOT_IDENTICAL === $op->type
                ) {
                    // Identical/not-identical only need the bool result. Release Temporary
                    // object boxes now (Zend statement-end) so WeakReference::get() temps do
                    // not outlive a following unset in the same CFG block (#27118).
                    $this->jitReleaseTempValueBoxAfterCompare($block, $binLeftOp);
                    $this->jitReleaseTempValueBoxAfterCompare($block, $binRightOp);
                    $this->jitReleasePendingWeakReferenceGetResult();
                }
                break;
            case OpCode::TYPE_EQUAL:
            case OpCode::TYPE_NOT_EQUAL:
            case OpCode::TYPE_LOGICAL_XOR:
            case OpCode::TYPE_SPACESHIP:
                if (null === $op->arg3) {
                    break;
                }
                $this->maybeRefreshIncludeBindingsBeforeUse();
                $this->assignOperand(
                    $this->operandAt($block, $op->arg1, \PHPCompiler\opcode_type_name($op->type).' result'),
                    $this->compileBinaryOp(
                        $op,
                        $this->variableFromOpForRuntimeRead($this->binaryOpLeftOperand($block, $op)),
                        $this->variableFromOpForRuntimeRead($this->operandAt($block, $op->arg3, \PHPCompiler\opcode_type_name($op->type).' right'))
                    )
                );
                break;
            case OpCode::TYPE_UNARY_MINUS:
            case OpCode::TYPE_BITWISE_NOT:
            case OpCode::TYPE_UNARY_PLUS:
                $this->assignOperand(
                    $block->getOperand($op->arg1),
                    OpCode::TYPE_UNARY_PLUS === $op->type
                        ? \PHPCompiler\JIT\JitUnaryPlus::lower(
                            $this->context,
                            $op,
                            $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        )
                        : (OpCode::TYPE_UNARY_MINUS === $op->type
                            ? \PHPCompiler\JIT\JitUnaryMinus::lower(
                                $this->context,
                                $op,
                                $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                            )
                            : $this->context->helper->unaryOp(
                                $op,
                                $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                            ))
                );
                break;
            default:
                throw new \LogicException('compileBinaryAndUnaryOp: unexpected opcode '.$op->type);
        }
    }
}
