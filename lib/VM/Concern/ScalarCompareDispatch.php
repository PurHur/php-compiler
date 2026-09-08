<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM compare / identical / relational / spaceship dispatch (#36403).
 *
 * Extracted from {@see ScalarCastCompareArithConcatDispatch} IDENTICAL through
 * SPACESHIP case bodies (php-src Zend/zend_vm_def.h ZEND_IS_IDENTICAL /
 * ZEND_IS_EQUAL / ZEND_IS_SMALLER* / ZEND_SPACESHIP / ZEND_BW_XOR as logical
 * xor; zend_operators.c compare / identical helpers). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * Counted-int for-loop fast path on TYPE_SMALLER previously used continue-2 on
 * the outer opcode while; that becomes return-$frame here so the caller
 * `goto restart` (same Frame-outcome convention as sibling Concern extracts).
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScalarCompareDispatch
{
    /**
     * Execute IDENTICAL / EQUAL / relational / SPACESHIP / LOGICAL_XOR for the
     * current opcode.
     *
     * @return Frame|int|null
     */
    private function executeScalarCompareDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_IDENTICAL:
            // Match arms lower to IDENTICAL — warn on undefined CV reads (#26147, #10358).
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            $arg1->bool($arg2->identicalTo($arg3));
            break;
        case OpCode::TYPE_NOT_IDENTICAL:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            $arg1->bool(!$arg2->identicalTo($arg3));
            $this->releaseVmBinaryOpOperandTemp($frame, (int) $op->arg2, (int) $op->arg1, (int) $op->arg3);
            $this->releaseVmBinaryOpOperandTemp($frame, (int) $op->arg3, (int) $op->arg1, (int) $op->arg2);
            break;
        case OpCode::TYPE_EQUAL:
            // Switch cases lower to EQUAL — same undefined-CV warning path (#26147).
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            try {
                $arg1->bool($arg2->equals($arg3, $this));
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            } catch (VM\MagicMethodInvocationAborted) {
                $this->clearTryCatchUnwindState();
                ++$frame->pos;
                break;
            }
            break;
        case OpCode::TYPE_NOT_EQUAL:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            try {
                $arg1->bool(!$arg2->equals($arg3, $this));
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            } catch (VM\MagicMethodInvocationAborted) {
                $this->clearTryCatchUnwindState();
                ++$frame->pos;
                break;
            }
            break;
        case OpCode::TYPE_LOGICAL_XOR:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            $arg1->bool($arg2->toBool($this) !== $arg3->toBool($this));
            break;
        case OpCode::TYPE_SMALLER:
            if (1 === $frame->pos) {
                $jumpIfOp = $frame->block->opCodes[1] ?? null;
                if ($jumpIfOp instanceof OpCode && OpCode::TYPE_JUMPIF === $jumpIfOp->type) {
                    $loopExit = $this->tryExecuteCountedIntForLoopAtJumpIf($frame, $jumpIfOp);
                    if (null !== $loopExit) {
                        $frame = $loopExit;
                        return $frame;
                    }
                }
            }
            // fall through
        case OpCode::TYPE_GREATER:
        case OpCode::TYPE_SMALLER_OR_EQUAL:
        case OpCode::TYPE_GREATER_OR_EQUAL:
            if ($this->tryExecuteRelationalCompareFastPath($frame, $op)) {
                break;
            }
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            try {
                $arg1->compareOp($op->type, $arg2, $arg3, $this);
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                // __toString throw during relational compare (#29534).
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            }
            break;
        case OpCode::TYPE_SPACESHIP:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            try {
                $arg1->spaceshipOp($arg2, $arg3, $this);
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                // __toString throw during <=> (#29534).
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            }
            break;
        default:
            throw new \LogicException(
                'ScalarCompareDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
