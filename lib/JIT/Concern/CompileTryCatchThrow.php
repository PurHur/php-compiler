<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * TRY / CATCH / FINALLY / THROW / RETHROW opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_TRY}, {@code TYPE_CATCH},
 * {@code TYPE_FINALLY}, {@code TYPE_THROW}, {@code TYPE_RETHROW}. Returns the original
 * entry basic block when the opcode seals the current block (same early-return as the
 * prior inlined cases); returns null when CATCH/FINALLY only finishes a post-try opcode
 * and the switch should continue. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_CATCH / ZEND_THROW / ZEND_FAST_CALL / ZEND_FAST_RET),
 * Zend/zend_exceptions.c — move-only Concern extract; no new C ABI.
 */
trait CompileTryCatchThrow
{
    /**
     * @param Variable ...$args
     */
    private function compileTryCatchThrowOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $origBasicBlock,
        Variable ...$args
    ): ?PHPLLVM\BasicBlock {
        switch ($op->type) {
            case OpCode::TYPE_TRY:
                \PHPCompiler\JIT\TryCatchHelper::beginTry($this, $func, $this->context, $block, $op, $i, $args);

                return $origBasicBlock;
            case OpCode::TYPE_CATCH:
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\TryCatchHelper::finishPostTryOpcode($this->context);

                    return null;
                }
                if (null !== $op->block1) {
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                }

                return $origBasicBlock;
            case OpCode::TYPE_FINALLY:
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\TryCatchHelper::finishPostTryOpcode($this->context);

                    return null;
                }
                if (null !== $op->block1) {
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                }

                return $origBasicBlock;
            case OpCode::TYPE_THROW:
                \PHPCompiler\JIT\TryCatchHelper::emitThrow($this, $this->context, $func, $block, $op);

                return $origBasicBlock;
            case OpCode::TYPE_RETHROW:
                \PHPCompiler\JIT\TryCatchHelper::emitRethrow($this, $this->context, $func, $block);

                return $origBasicBlock;
            default:
                throw new \LogicException('Unexpected opcode in compileTryCatchThrowOp: '.$op->type);
        }
    }
}
