<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\IssetHelper;
use PHPLLVM;

/**
 * empty / isset / eval opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_EMPTY},
 * {@code TYPE_EMPTY_OBJECT_PROPERTY}, {@code TYPE_EMPTY_STATIC_PROPERTY},
 * {@code TYPE_EMPTY_DIMENSION}, {@code TYPE_EVAL}, {@code TYPE_ISSET}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_ISSET_ISEMPTY_CV / ZEND_ISSET_ISEMPTY_VAR /
 * ZEND_ISSET_ISEMPTY_DIM_OBJ / ZEND_ISSET_ISEMPTY_PROP_OBJ /
 * ZEND_ISSET_ISEMPTY_STATIC_PROP), Zend/zend_execute_API.c (zend_is_true /
 * zend_isset_isempty_dim_handler), Zend/zend_eval_execute — move-only Concern
 * extract; no new C ABI.
 */
trait CompileEmptyIssetEval
{
    private function compileEmptyIssetEvalOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func
    ): void {
        switch ($op->type) {
            case OpCode::TYPE_EMPTY:
                $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $emptyResult = \PHPCompiler\JIT\EmptyObjectPropertyHelper::compileEmptyFromValue(
                    $this->context,
                    $from
                );
                // Force: php-cfg leaves FuncCall args on dead temps while ARG_SEND is
                // remapped to this empty/isset result; empty usages would skip the store
                // and ARG_SEND would materialize a null box (AOT var_export NULL, #28622).
                $this->assignOperandValue(
                    $block->getOperand($op->arg1),
                    $emptyResult,
                    true
                );
                break;
            case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                $containerOp = $block->getOperand($op->arg2);
                $dimOp = $block->getOperand($op->arg3);
                $container = $this->context->getVariableFromOp($containerOp);
                $dim = $this->context->getVariableFromOp($dimOp);
                $emptyResult = \PHPCompiler\JIT\EmptyObjectPropertyHelper::compile(
                    $this->context,
                    $container,
                    $dim,
                    $dimOp,
                    $containerOp
                );
                $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                break;
            case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                $classOp = $block->getOperand($op->arg2);
                $nameOp = $block->getOperand($op->arg3);
                $emptyResult = \PHPCompiler\JIT\EmptyStaticPropertyHelper::compile(
                    $this->context,
                    $classOp,
                    $nameOp
                );
                $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                break;
            case OpCode::TYPE_EMPTY_DIMENSION:
                $containerOp = $block->getOperand($op->arg2);
                $dimOp = $block->getOperand($op->arg3);
                $container = $this->context->getVariableFromOp($containerOp);
                $dim = $this->context->getVariableFromOp($dimOp);
                $emptyResult = \PHPCompiler\JIT\EmptyDimensionHelper::compile(
                    $this->context,
                    $container,
                    $dim,
                    $dimOp,
                    $containerOp
                );
                $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                break;
            case OpCode::TYPE_EVAL:
                \PHPCompiler\JIT\EvalHelper::compile($this, $func, $block, $op);
                break;
            case OpCode::TYPE_ISSET:
                $containerOp = $block->getOperand($op->arg2);
                $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                if ($op->issetOnStaticProperty) {
                    $issetResult = IssetHelper::compileStaticProperty(
                        $this->context,
                        $containerOp,
                        $dimOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult, true);
                    break;
                }
                $container = $this->context->getVariableFromOp($containerOp);
                $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                $issetResult = IssetHelper::compile(
                    $this->context,
                    $container,
                    $dim,
                    $dimOp,
                    $containerOp,
                    $op->issetOnProperty
                );
                // Force store: see TYPE_EMPTY above (#28622 / peer #11498).
                $this->assignOperandValue($block->getOperand($op->arg1), $issetResult, true);
                break;
            default:
                throw new \LogicException('compileEmptyIssetEvalOp: unexpected opcode '.$op->type);
        }
    }
}
