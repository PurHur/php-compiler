<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Type-assert and error-silence opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_TYPE_ASSERT},
 * {@code TYPE_BEGIN_SILENCE}, and {@code TYPE_END_SILENCE}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_VERIFY_* / ZEND_BEGIN_SILENCE / ZEND_END_SILENCE),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait CompileTypeAssertAndErrorSilence
{
    private function compileTypeAssertOp(Block $block, OpCode $op): void
    {
        $this->assignOperand(
            $block->getOperand($op->arg1),
            $this->context->getVariableFromOp($block->getOperand($op->arg2))
        );
    }

    private function compileBeginSilenceOp(): void
    {
        \PHPCompiler\JIT\ErrorSilenceHelper::beginSilence($this->context);
    }

    private function compileEndSilenceOp(): void
    {
        \PHPCompiler\JIT\ErrorSilenceHelper::endSilence($this->context);
    }
}
