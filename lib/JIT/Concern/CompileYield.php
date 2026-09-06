<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPLLVM;

/**
 * YIELD / YIELD_FROM opcode lowering for JIT/AOT generator resume (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_YIELD} and
 * {@code TYPE_YIELD_FROM}. Returns the resume-continuation basic block when
 * compiling a generator resume body (caller rebinds {@code $basicBlock} /
 * {@code $origBasicBlock}); throws when yield is seen outside resume
 * compilation (generators remain VM-only for first emit — issue #167).
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_YIELD / ZEND_YIELD_FROM),
 * Zend/zend_generators.c — move-only Concern extract; no new C ABI.
 */
trait CompileYield
{
    private function compileYieldOp(Block $block, OpCode $op): PHPLLVM\BasicBlock
    {
        if (!$this->context->compilingGeneratorResume) {
            throw new \LogicException('Generators (yield) are VM-only (issue #167)');
        }
        $yieldId = spl_object_id($op);
        if (!isset($this->context->generatorYieldPointIndex[$yieldId])) {
            throw new \LogicException('yield opcode missing from resume-point index (#35142)');
        }
        $pointIndex = $this->context->generatorYieldPointIndex[$yieldId];
        $stateParam = $this->context->generatorStateParam;
        assert(null !== $stateParam);
        if (OpCode::TYPE_YIELD_FROM === $op->type) {
            \PHPCompiler\VM\GeneratorYieldFromJitHelper::emitYieldFromPoint(
                $this,
                $block,
                $op,
                $stateParam,
                $pointIndex
            );
        } elseif (OpCode::TYPE_YIELD === $op->type) {
            \PHPCompiler\VM\GeneratorIteratorJitHelper::emitYieldPoint(
                $this,
                $block,
                $op,
                $stateParam,
                $pointIndex + 1
            );
        } else {
            throw new \LogicException('Unexpected opcode in compileYieldOp: '.$op->type);
        }
        $contIp = $pointIndex + 1;
        if (!isset($this->context->generatorResumeContinuations[$contIp])) {
            throw new \LogicException('generator resume continuation missing for ip '.$contIp.' (#35142)');
        }

        $cont = $this->context->generatorResumeContinuations[$contIp];
        $this->context->builder->positionAtEnd($cont);

        return $cont;
    }
}
