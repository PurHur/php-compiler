<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM compare / arith / bitwise / unary / concat dispatch (#36403).
 *
 * CAST_* moved to {@see ScalarCastDispatch}. Remaining IDENTICAL through CONCAT
 * case bodies (php-src Zend/zend_vm_def.h ZEND_IS_* / ZEND_ADD..POW /
 * ZEND_BW_* / ZEND_BOOL_NOT adjacent unary / ZEND_CONCAT; zend_operators.c).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * Counted-int for-loop fast path on TYPE_SMALLER previously used continue-2 on
 * the outer opcode while; that becomes return-$frame here so the caller
 * `goto restart` (same Frame-outcome convention as sibling Concern extracts).
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScalarCastCompareArithConcatDispatch
{
    /**
     * Execute compare, inc/dec, arith, bitwise, unary, and CONCAT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeScalarCastCompareArithConcatDispatch(Frame $frame, OpCode $op): Frame|int|null
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
        case OpCode::TYPE_POST_INC:
            $catchFrame = $this->executeIncDec($frame, $op, true, false);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            break;
        case OpCode::TYPE_PRE_INC:
            $catchFrame = $this->executeIncDec($frame, $op, true, true);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            break;
        case OpCode::TYPE_POST_DEC:
            $catchFrame = $this->executeIncDec($frame, $op, false, false);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            break;
        case OpCode::TYPE_PRE_DEC:
            $catchFrame = $this->executeIncDec($frame, $op, false, true);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            break;
        case OpCode::TYPE_PLUS:
        case OpCode::TYPE_MINUS:
        case OpCode::TYPE_MUL:
        case OpCode::TYPE_DIV:
        case OpCode::TYPE_MODULO:
        case OpCode::TYPE_POW:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $frame->scope[$op->arg2];
            $arg3 = $frame->scope[$op->arg3];
            $readArg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $readArg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
            $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            if ($op->arg1 === $op->arg2) {
                $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg2, $frame);
                if (null !== $hookedRead) {
                    $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        return $frame;
                    }
                    break;
                }
            }
            try {
                $numericArg2 = $op->arg1 !== $op->arg2 ? $readArg2 : $arg2;
                $numericArg3 = $readArg3;
                if (
                    $op->isIncDec
                    && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)
                ) {
                    $arg1->incDecOp($op->type, $numericArg2, $numericArg3, $this, $frame);
                } else {
                    $arg1->numericOp($op->type, $numericArg2, $numericArg3, $this, $frame);
                }
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\DivisionByZeroError $e) {
                $catchFrame = $this->dispatchVmDivisionByZeroError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\ArithmeticError $e) {
                $catchFrame = $this->dispatchVmArithmeticError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            }
            $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
            break;
        case OpCode::TYPE_BITWISE_AND:
        case OpCode::TYPE_BITWISE_OR:
        case OpCode::TYPE_BITWISE_XOR:
        case OpCode::TYPE_SHIFT_LEFT:
        case OpCode::TYPE_SHIFT_RIGHT:
            $arg1 = $frame->scope[$op->arg1];
            $readArg2 = $this->readRuntimeOperandForBitwise($frame, (int) $op->arg2);
            $readArg3 = $this->readRuntimeOperandForBitwise($frame, (int) $op->arg3);
            $arg2 = $op->arg1 !== $op->arg2 ? $readArg2 : $frame->scope[$op->arg2];
            $arg3 = $readArg3;
            $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            if ($op->arg1 === $op->arg2) {
                $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg2, $frame);
                if (null !== $hookedRead) {
                    $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        return $frame;
                    }
                    break;
                }
            }
            try {
                $arg1->bitwiseOp($op->type, $arg2, $arg3, $this, $frame);
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\ArithmeticError $e) {
                // Negative << / >> — Zend ArithmeticError must be user-catchable (#21912).
                $catchFrame = $this->dispatchVmArithmeticError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            }
            $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
            break;

        case OpCode::TYPE_UNARY_MINUS:
        case OpCode::TYPE_UNARY_PLUS:
        case OpCode::TYPE_BITWISE_NOT:
            $arg1 = $frame->scope[$op->arg1];
            $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            try {
                $arg1->unaryOp($op->type, $arg2, $this, $frame);
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            }
            break;
        case OpCode::TYPE_CONCAT:
            $arg1 = $frame->scope[$op->arg1];
            $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg1);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            // "$this" / concat with this CV — Error outside object context (#31728).
            $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg3);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                return $frame;
            }
            if ($op->arg1 === $op->arg2) {
                $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg1, $frame);
                if (null !== $hookedRead) {
                    $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        return $frame;
                    }
                    break;
                }
            }
            try {
                // Zend: assign-op on string offsets before concat (#22897).
                Variable::rejectAssignOpOnStringOffset(
                    $arg1,
                    $frame->scope[(int) $op->arg2]
                );
                $left = $op->arg1 === $op->arg2
                    ? $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2)
                    : $this->readRuntimeOperandForConcat($frame, (int) $op->arg2);
                $right = $this->readRuntimeOperandForConcat($frame, (int) $op->arg3);
                $result = new Variable();
                $result->string(
                    $this->coerceVariableToString($left, $frame)
                    . $this->coerceVariableToString($right, $frame)
                );
                $arg1->copyFrom($result);
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                // __toString throw during concat — resume catch on outer stack (#29521).
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            } catch (VM\MagicMethodInvocationAborted) {
                $this->clearTryCatchUnwindState();
                ++$frame->pos;
                $frame->suppressNextEcho = true;
                break;
            }
            $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
            break;
        default:
            throw new \LogicException(
                'ScalarCastCompareArithConcatDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
