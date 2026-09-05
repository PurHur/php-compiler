<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Scalar / array / object / unset cast opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_CAST_BOOL} through
 * {@code TYPE_CAST_UNSET}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_BOOL / ZEND_CAST), Zend/zend_operators.c
 * (convert_to_*), Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileCast
{
    private function compileCastOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_CAST_BOOL:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $bool = ext\standard\JitZendScalarCast::emitBoolCast($this->context, $value);
                $this->assignOperandValue($block->getOperand($op->arg1), $bool, true);
                break;
            case OpCode::TYPE_CAST_INT:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $value);
                // Force: php-cfg leaves inline (int)/(float) call args on dead temps
                // while ARG_SEND is remapped to the cast result; empty usages would
                // skip the store and ARG_SEND would materialize NULL (#32293 / #28622).
                $this->assignOperandValue($block->getOperand($op->arg1), $long, true);
                break;
            case OpCode::TYPE_CAST_FLOAT:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $double = ext\standard\JitZendScalarCast::emitFloatCast($this->context, $value);
                $this->assignOperandValue($block->getOperand($op->arg1), $double, true);
                break;
            case OpCode::TYPE_CAST_STRING:
                $castSrcOp = $block->getOperand($op->arg2);
                $value = $this->context->getVariableFromOp($castSrcOp);
                $castClassHint = $castSrcOp->type?->userType ?? null;
                $this->assignOperand(
                    $block->getOperand($op->arg1),
                    \PHPCompiler\JIT\JitNativeString::coerce(
                        $this->context,
                        $value,
                        $castSrcOp,
                        \is_string($castClassHint) ? $castClassHint : null
                    )
                );
                break;
            case OpCode::TYPE_CAST_VOID:
                $this->assignOperand($block->getOperand($op->arg1), $this->jitNullVariable());
                break;
            case OpCode::TYPE_CAST_ARRAY:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $this->assignOperand(
                    $block->getOperand($op->arg1),
                    \PHPCompiler\JIT\CastHelper::emitArrayCast($this->context, $value)
                );
                break;
            case OpCode::TYPE_CAST_OBJECT:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $this->assignOperand(
                    $block->getOperand($op->arg1),
                    \PHPCompiler\JIT\CastHelper::emitObjectCast($this->context, $value, $block, $op)
                );
                break;
            case OpCode::TYPE_CAST_UNSET:
                $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $this->assignOperand(
                    $block->getOperand($op->arg1),
                    \PHPCompiler\JIT\CastHelper::emitUnsetCast($this->context, $value)
                );
                break;
            default:
                throw new \LogicException('compileCastOp: unexpected opcode '.$op->type);
        }
    }
}
