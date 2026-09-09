<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM inc/dec / arith / bitwise / unary dispatch (#36403).
 *
 * Extracted from {@see ScalarCastCompareArithConcatDispatch} POST_INC through
 * BITWISE_NOT case bodies (php-src Zend/zend_vm_def.h ZEND_POST_INC..POW /
 * ZEND_BW_* / ZEND_BOOL_NOT-adjacent unary; zend_operators.c add/sub/mul/div/
 * mod/pow / bitwise / unary helpers). CONCAT remains in the parent Concern.
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScalarArithBitwiseUnaryDispatch
{
    /**
     * Execute inc/dec, arith, bitwise, and unary opcodes for the current op.
     *
     * @return Frame|int|null
     */
    private function executeScalarArithBitwiseUnaryDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
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
        default:
            throw new \LogicException(
                'ScalarArithBitwiseUnaryDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
