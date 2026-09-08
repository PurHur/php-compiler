<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_LIST_UNPACK_CHECK / TYPE_LIST_SPREAD_ASSIGN dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_LIST / ZEND_ASSIGN / list() spread
 * tail materialize; zend_compile.c list destructuring). Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve.
 * Unpackability / Traversable materialize helpers live in
 * {@see UserInvokeArrayAccessAndClosureCall}. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ListUnpackAndSpreadAssignDispatch
{
    /**
     * Execute LIST_UNPACK_CHECK / LIST_SPREAD_ASSIGN for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeListUnpackAndSpreadAssignDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_LIST_UNPACK_CHECK:
            $unpackSlot = $frame->scope[$op->arg2];
            $unpack = $unpackSlot->resolveIndirect();
            if (null !== $op->block1) {
                if (!$this->variableIsListDestructUnpackable($unpack)) {
                    // Plain / Traversable-only objects: Zend FETCH_LIST Error (#25096).
                    if (Variable::TYPE_OBJECT === $unpack->type) {
                        $className = $unpack->toObject()->class->name;
                        $catchFrame = $this->dispatchVmError(
                            'Cannot use object of type ' . $className . ' as array',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            return $catchFrame;
                        }

                        return null;
                    }
                    // By-ref list / `$r =& $s[$i]`: do not skip — FETCH_DIM_W + ASSIGN_REF
                    // raise Zend string-offset or scalar-as-array Errors (#21910).
                    if ($op->listUnpackHasByRef) {
                        return null;
                    }
                    foreach ($op->listUnpackNullInitSlots as $destSlot) {
                        $dest = $frame->scope[(int) $destSlot];
                        $dest->resolveIndirect()->null();
                        $this->markScopeSlotInitialized($frame, (int) $destSlot);
                    }
                    if (null !== $op->block1) {
                        foreach ($op->listUnpackNullInitSlots as $destSlot) {
                            unset($op->block1->constants[(int) $destSlot]);
                        }
                    }
                    // String and other non-array RHS: skip slot binds, targets read as NULL (#4325, #10486).
                    return $this->frameForBranch($frame, $op->block1);
                }
                $catchFrame = $this->materializeListDestructIterableRhs($unpackSlot, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $frame->listUnpackAssignMergeBlock = $op->block1;

                return null;
            }

            return null;
        case OpCode::TYPE_LIST_SPREAD_ASSIGN:
            if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
                throw new \Error('Spread operator is not supported in assignments');
            }
            $dest = $frame->scope[$op->arg1];
            $src = $frame->scope[$op->arg2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $src->type) {
                if (null !== $op->block1) {
                    return $this->frameForBranch($frame, $op->block1);
                }

                return null;
            }
            if (!isset($frame->block->constants[$op->arg3])) {
                throw new \LogicException('list spread assign requires compile-time offset');
            }
            $offset = $frame->block->constants[$op->arg3]->toInt();
            $ht = $src->toArray();
            $excludedKeys = $op->listSpreadExcludedKeys;
            if ([] !== $excludedKeys) {
                $tail = $ht->copyListSpreadTail($offset, $excludedKeys);
            } else {
                if (!\PHPCompiler\ext\standard\VmArray::isList($ht)) {
                    $catchFrame = $this->dispatchVmTypeError(
                        new \TypeError('Cannot unpack array with string keys'),
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return null;
                }
                $tail = $ht->sliceCopy($offset, null);
            }
            $dest->array($tail);

            return null;
        default:
            throw new \LogicException(
                'ListUnpackAndSpreadAssignDispatch: unexpected opcode '.$op->type
            );
        }
    }
}
