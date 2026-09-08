<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\Variable;

/**
 * VM CAST_* dispatch (#36403).
 *
 * Extracted from {@see ScalarCastCompareArithConcatDispatch} CAST_BOOL through
 * CAST_VOID case bodies (php-src Zend/zend_vm_def.h ZEND_CAST; zend_operators.c
 * convert_to_*). Concern trait — same namespace as parent so relative Frame /
 * OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScalarCastDispatch
{
    /**
     * Execute CAST_BOOL / CAST_INT / CAST_FLOAT / CAST_STRING / CAST_ARRAY /
     * CAST_OBJECT / CAST_UNSET / CAST_VOID for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeScalarCastDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_CAST_BOOL:
            try {
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_BOOLEAN,
                    $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                    $this
                );
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
            }
            break;
        case OpCode::TYPE_CAST_INT:
            try {
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_INTEGER,
                    $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                    $this,
                    $frame
                );
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
            }
            break;
        case OpCode::TYPE_CAST_FLOAT:
            try {
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_FLOAT,
                    $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                    $this,
                    $frame
                );
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
            }
            break;
        case OpCode::TYPE_CAST_STRING:
            $savedCallSiteLine = $frame->callSiteLine;
            if (null !== $op->arg3 && $op->arg3 > 0) {
                $frame->callSiteLine = $op->arg3;
            }
            // Encapsed "$this" / "{$this}" lowers to CAST_STRING on the this CV (#31728).
            $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
            if (null !== $catchFrame) {
                $frame->callSiteLine = $savedCallSiteLine;
                $frame = $catchFrame;
                return $frame;
            }
            $castStringSrc = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            try {
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_STRING,
                    $castStringSrc,
                    $this,
                    $frame
                );
            } catch (\Error $e) {
                $frame->callSiteLine = $savedCallSiteLine;
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\TypeError $e) {
                $frame->callSiteLine = $savedCallSiteLine;
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (\BadMethodCallException $e) {
                // SPL CachingIterator::__toString without CALL_TOSTRING (#24907).
                $frame->callSiteLine = $savedCallSiteLine;
                $catchFrame = $this->dispatchVmBadMethodCallException($e, $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    return $frame;
                }
                break;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                $frame->callSiteLine = $savedCallSiteLine;
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                return $frame;
            } catch (VM\MagicMethodInvocationAborted) {
                $frame->callSiteLine = $savedCallSiteLine;
                $this->clearTryCatchUnwindState();
                ++$frame->pos;
                break;
            }
            $frame->callSiteLine = $savedCallSiteLine;
            break;
        case OpCode::TYPE_CAST_ARRAY:
            $frame->scope[$op->arg1]->copyFrom(
                CastSupport::toArray(
                    $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                    $this->context->classes
                )
            );
            break;
        case OpCode::TYPE_CAST_OBJECT:
            $src = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
            $dst = $frame->scope[$op->arg1];
            $dst->copyFrom(VM\CastSupport::toObject($src, $this->context->classes));
            $this->markScopeSlotInitialized($frame, (int) $op->arg1);
            break;
        case OpCode::TYPE_CAST_UNSET:
            $src = $frame->scope[$op->arg2];
            if ($this->slotIsReferenceBinding($src, $frame->scope)) {
                $src->reset();
                $src->type = Variable::TYPE_UNDEFINED;
            }
            $frame->scope[$op->arg1]->null();
            break;
        case OpCode::TYPE_CAST_VOID:
            $frame->scope[$op->arg1]->null();
            break;
        default:
            throw new \LogicException(
                'ScalarCastDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
