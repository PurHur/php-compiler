<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * list() unpack / spread-assign opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_LIST_UNPACK_CHECK},
 * {@code TYPE_LIST_SPREAD_ASSIGN}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_FE_RESET_R / ZEND_FE_FETCH_R list paths,
 * ZEND_ASSIGN_DIM / unpack), Zend/zend_execute.c list destructuring —
 * move-only Concern extract; no new C ABI.
 */
trait CompileListUnpack
{
    private function compileListUnpackOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func
    ): void {
        $builder = $this->context->builder;
        switch ($op->type) {
            case OpCode::TYPE_LIST_UNPACK_CHECK:
                if (null !== $op->block1) {
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (!$this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
                        ++self::$blockNumber;
                        $mergeBody = $func->appendBasicBlock('block_'.self::$blockNumber);
                        $this->context->listUnpackMergeLlvmBlocks[$op->block1] = $mergeBody;
                    } else {
                        $mergeBody = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
                    }
                    $this->context->listUnpackAssignRootBlock = $block;
                    $this->context->listUnpackSkipAssignPath = \PHPCompiler\JIT\ListUnpackHelper::emitGuardedListUnpackCheck(
                        $this->context,
                        $array,
                        $branchBlock,
                        $mergeBody,
                        $block->getOperand($op->arg2),
                        $op->listUnpackHasByRef,
                        $this
                    );
                    break;
                }
                \PHPCompiler\JIT\ListUnpackHelper::emitCheck(
                    $this->context,
                    $this->context->getVariableFromOp($block->getOperand($op->arg2))
                );
                break;
            case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
                    throw new \Error('Spread operator is not supported in assignments');
                }
                if ($this->context->listUnpackSkipAssignPath) {
                    break;
                }
                if (!isset($block->constants[$op->arg3])) {
                    throw new \LogicException('list spread assign requires compile-time offset');
                }
                $spreadDestOp = $block->getOperand($op->arg1);
                $spreadSrc = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $spreadI64 = $this->context->getTypeFromString('int64');
                $spreadI1 = $this->context->getTypeFromString('int1');
                $spreadOffset = $spreadI64->constInt($block->constants[$op->arg3]->toInt(), false);
                if ([] !== $op->listSpreadExcludedKeys) {
                    $spreadTailHt = \PHPCompiler\JIT\Builtin\ListSpreadTailRuntime::copyTail(
                        $this->context,
                        $spreadSrc,
                        $spreadOffset,
                        $op->listSpreadExcludedKeys
                    );
                } else {
                    if (!\PHPCompiler\JIT\ListUnpackHelper::isDefinitelyNonArrayAtCompileTime($this->context, $spreadSrc)) {
                        \PHPCompiler\JIT\ListUnpackHelper::emitIsListBranchOrFail($this->context, $spreadSrc);
                    }
                    $spreadTailHt = \PHPCompiler\JIT\Builtin\ArraySliceRuntime::slice(
                        $this->context,
                        $spreadSrc,
                        $spreadOffset,
                        $spreadI1->constInt(0, false),
                        $spreadI64->constInt(0, false)
                    );
                }
                $spreadDestVar = $this->context->getVariableFromOp($spreadDestOp);
                if (0 !== ($spreadDestVar->type & Variable::IS_NATIVE_ARRAY)) {
                    $spreadBox = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                    $this->context->setVariableOp(
                        $spreadDestOp,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VARIABLE,
                            $spreadBox
                        )
                    );
                }
                $spreadTailVar = new Variable(
                    $this->context,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $spreadTailHt
                );
                $this->assignOperand($spreadDestOp, $spreadTailVar);
                break;
            default:
                throw new \LogicException('compileListUnpackOp: unexpected opcode '.$op->type);
        }
    }
}
