<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPLLVM;

/**
 * FROM_CALLABLE opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_FROM_CALLABLE}.
 * Returns null when the caller should {@code break}; otherwise returns the
 * basic block {@see compileBlockInternal} should return (FCC TypeError/Error paths).
 *
 * php-src: Zend/zend_closures.c (zend_create_closure / Closure::fromCallable),
 * Zend/zend_API.c (zend_is_callable_ex) — move-only Concern extract; no new C ABI.
 */
trait CompileFromCallable
{
    private function compileFromCallableOp(
        Block $block,
        OpCode $op,
        PHPLLVM\BasicBlock $origBasicBlock
    ): ?PHPLLVM\BasicBlock {
        try {
            $closureVar = \PHPCompiler\JIT\FromCallableHelper::createClosureVariable($this->context, $block, $op);
            if (null !== $closureVar->closureCall) {
                $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $closureVar->closureCall;
            }
            $this->assignOperand($block->getOperand($op->arg1), $closureVar, true);
        } catch (\TypeError $e) {
            // Closure::fromCallable TypeError precedes Error (TypeError extends Error) (#27138).
            $file = '';
            $line = 0;
            if (null !== $op->sourceLocation) {
                $file = $op->sourceLocation->filename;
                $line = $op->sourceLocation->startLine;
            }
            if ('' === $file) {
                $file = $block->scriptPath();
                if ('' === $file) {
                    $file = $this->context->jitAotEntryScriptPath;
                }
            }
            if ([] !== $this->context->tryCatch->handlerStack) {
                \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                    $this->context,
                    'TypeError',
                    $e->getMessage(),
                    $this,
                    $file,
                    $line
                );
            } else {
                \PHPCompiler\JIT\TryCatchHelper::emitPendTypeErrorForCaller($this->context, $e->getMessage());
                \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
                \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
                \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise($this->context, $e->getMessage());
                \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitAbortIfPendingForStandaloneMain($this->context);
            }
            $this->context->builder->clearInsertionPosition();

            return $origBasicBlock;
        } catch (\Error $e) {
            // Compile-time FCC reject → catchable runtime Error at FCC site (#24397, #27106).
            $file = '';
            $line = 0;
            if (null !== $op->sourceLocation) {
                $file = $op->sourceLocation->filename;
                $line = $op->sourceLocation->startLine;
            }
            if ('' === $file) {
                $file = $block->scriptPath();
                if ('' === $file) {
                    $file = $this->context->jitAotEntryScriptPath;
                }
            }
            if ([] !== $this->context->tryCatch->handlerStack) {
                \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                    $this->context,
                    'Error',
                    $e->getMessage(),
                    $this,
                    $file,
                    $line
                );
            } else {
                // Pend + abort_if_pending prints Zend-shaped fatal (not libc abort) (#27106).
                \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $e->getMessage());
                \PHPCompiler\JIT\Builtin\ErrorRaise::emitAbortIfPendingForStandaloneMain($this->context);
            }
            // Stop like TYPE_THROW — further ops insert before the terminator (#27106).
            $this->context->builder->clearInsertionPosition();

            return $origBasicBlock;
        }
        return null;
    }
}
