<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_INIT_ARRAY / TYPE_ADD_ARRAY_ELEMENT / TYPE_ARRAY_SPREAD dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_vm_def.h ZEND_INIT_ARRAY / ZEND_ADD_ARRAY_ELEMENT /
 * ZEND_ADD_ARRAY_UNPACK; zend_hash_* / array unpack in zend_execute.c).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Element materialize lives in
 * {@see UserInvokeArrayAccessAndClosureCall}::materializeArrayElementForStorage;
 * spread body in {@see VM\ArraySpread}. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ArrayInitSpreadDispatch
{
    /**
     * Execute INIT_ARRAY / ADD_ARRAY_ELEMENT / ARRAY_SPREAD for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeArrayInitSpreadDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_INIT_ARRAY:
            $result = $frame->scope[$op->arg1];
            $result->newArray();
            if (is_null($op->arg2)) {
                return null;
            }
            // Fall through intentional — first element after empty init.
        case OpCode::TYPE_ADD_ARRAY_ELEMENT:
            try {
                $result = $frame->scope[$op->arg1];
                $catchFrame = $this->rejectMagicGetIndirectModify($result, true, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $ht = $result->toArray();
                if (is_null($op->arg3)) {
                    $ht->append($this->materializeArrayElementForStorage(
                        $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                    ));

                    return null;
                }
                $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                $value = $this->materializeArrayElementForStorage(
                    $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                );
                // Array-literal keys share assignment's typed TypeError (#28628 / zend_illegal_container_offset).
                // Resource keys warn+cast (#29550); float precision via normalizeIndexKeyForWrite.
                $key = VM\HashTable::normalizeIndexKeyForWrite($key, $this->context, $frame);
                if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                    $ht->updateIndex(
                        $key->is(Variable::TYPE_FLOAT)
                            ? \PHPCompiler\ext\standard\VmMath::floatToZendLong($key->toFloat())
                            : $key->toInt(),
                        $value
                    );
                } elseif ($key->is(Variable::TYPE_STRING)) {
                    $ht->update($key->toString(), $value);
                } else {
                    throw new \TypeError(VM\EnumCaseSupport::illegalArrayOffsetMessage($key));
                }
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }

            return null;
        case OpCode::TYPE_ARRAY_SPREAD:
            try {
                $result = $frame->scope[$op->arg1];
                $source = $frame->scope[$op->arg2];
                VM\ArraySpread::spreadInto(
                    $this,
                    $frame,
                    $result->toArray(),
                    $source,
                    (int) ($op->arg3 ?? 0)
                );
            } catch (\TypeError $e) {
                // TypeError extends Error — must precede catch (\Error) (#27952).
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }

            return null;
        default:
            throw new \LogicException(
                'ArrayInitSpreadDispatch: unexpected opcode '.$op->type
            );
        }
    }
}
