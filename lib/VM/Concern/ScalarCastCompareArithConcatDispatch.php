<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM CONCAT dispatch (#36403).
 *
 * CAST_* in {@see ScalarCastDispatch}; IDENTICAL..SPACESHIP in
 * {@see ScalarCompareDispatch}; POST_INC..BITWISE_NOT in
 * {@see ScalarArithBitwiseUnaryDispatch}. Remaining CONCAT case body
 * (php-src Zend/zend_vm_def.h ZEND_CONCAT; zend_operators.c concat /
 * convert_to_string). Concern trait — same namespace as parent so relative
 * Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScalarCastCompareArithConcatDispatch
{
    /**
     * Execute CONCAT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeScalarCastCompareArithConcatDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
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
