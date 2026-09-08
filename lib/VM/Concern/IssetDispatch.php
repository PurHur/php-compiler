<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ISSET dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner isset case body
 * (php-src Zend/zend_vm_def.h ZEND_ISSET_ISEMPTY_VAR / ZEND_ISSET_ISEMPTY_DIM_OBJ /
 * ZEND_ISSET_ISEMPTY_PROP_OBJ; zend_execute.c isset/empty handlers; dim/property
 * ArrayAccess + extension has_dimension parity). Concern trait — same namespace as
 * parent so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait IssetDispatch
{
    /**
     * Execute TYPE_ISSET for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIssetDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        // Never mutate a hash-table bucket (or a stale FETCH_DIM read indirect)
        // in place when materialising the isset bool — slot reuse after
        // FETCH_DIM_IS left `$m[0]` as bool true for multi-arg nested isset (#36398 /
        // same class as #36380 Parsedown).
        $issetDstSlot = (int) $op->arg1;
        $dst = $frame->scope[$issetDstSlot];
        if (
            $dst->hashTableBucketCell
            || ($dst->isIndirect() && !$dst->phpReference && !$dst->propertyAssignLvalue)
        ) {
            $fresh = new Variable();
            $frame->scope[$issetDstSlot] = $fresh;
            $dst = $fresh;
        }
        if (null === $op->arg3 && $this->isUnboundThisSlot($frame, (int) $op->arg2)) {
            $dst->bool(false);
            return null;
        }
        if (null !== $op->arg3) {
            if ($op->issetOnStaticProperty) {
                $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
                $propNameRaw = $frame->scope[$op->arg3]->toString();
                $dst->bool($this->staticPropertyIsSetForCoalesceAssign($lcClass, $propNameRaw));
                return null;
            }
            $container = $frame->scope[$op->arg2]->resolveIndirect();
            if (Variable::TYPE_ENUM_CASE === $container->type) {
                [$propName, $catchFrame] = $this->coerceRuntimeOperandToString(
                    $frame->scope[$op->arg3],
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforcePropertyName($propName, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $dst->bool(VM\EnumCaseSupport::propertyExistsOnCase(
                    $container->toEnumCase()->enumClass,
                    $propName
                ));
                return null;
            }
            if (Variable::TYPE_ARRAY === $container->type) {
                if ($this->context->isGlobalsTable($container)) {
                    $dst->bool($this->context->globalsTableOffsetIsSet($frame->scope[$op->arg3]));
                    return null;
                }
                if ($op->issetOnProperty) {
                    $dst->bool(false);
                    return null;
                }
                try {
                    $dst->bool($container->toArray()->offsetIsSet($frame->scope[$op->arg3], $frame));
                } catch (\TypeError $e) {
                    $catchFrame = $this->dispatchVmTypeError($e, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                }
                return null;
            }
            if (Variable::TYPE_OBJECT === $container->type) {
                $object = $container->toObject();
                if (VM\EnumCaseSupport::isEnumCase($object)) {
                    [$propName, $catchFrame] = $this->coerceRuntimeOperandToString(
                        $frame->scope[$op->arg3],
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $catchFrame = $this->enforcePropertyName($propName, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $dst->bool(VM\EnumCaseSupport::propertyExistsOnCase($object->class, $propName));
                    return null;
                }
                if (
                    !$op->issetOnProperty
                    && null !== ($dimHandler = $this->context->findObjectDimensionHandler($object))
                    && null !== $dimHandler->has
                ) {
                    // isset($list[$i]) via extension has_dimension (php-src php_dom.c; #20311 / #36204).
                    // TokenList illegal offsets TypeError (token_list.c; #23006).
                    try {
                        $dst->bool(($dimHandler->has)(
                            $object,
                            $frame->scope[$op->arg3]
                        ));
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            return $catchFrame;
                        }
                    }
                    return null;
                }
                if (
                    !$op->issetOnProperty
                    && $this->objectImplementsArrayAccess($object)
                ) {
                    // ArrayObject/ArrayIterator native has_dimension(isset): null ≠ set (#24251).
                    // User offsetExists overrides keep ArrayAccess isset == offsetExists (php-src).
                    $nativeSplIsset = $this->nativeSplArrayDimensionIsSet(
                        $object,
                        $frame->scope[$op->arg3]
                    );
                    if (null !== $nativeSplIsset) {
                        $dst->bool($nativeSplIsset);
                        return null;
                    }
                    // isset($obj[$k]) via ArrayAccess::offsetExists — not isset($obj->prop) (#19707).
                    $existsOut = new Variable();
                    $catchFrame = $this->invokeArrayAccessOffsetExists(
                        $object,
                        $frame->scope[$op->arg3],
                        $frame,
                        $existsOut
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $dst->bool($existsOut->toBool());
                    return null;
                }
                if (!$op->issetOnProperty) {
                    // Resource as array subject — isset soft-false like scalars (zend_execute.c, #30028).
                    if (VM\ResourceSupport::isResourceObject($object)) {
                        $dst->bool(false);
                        return null;
                    }
                    // isset($obj[$k]) without has_dimension / ArrayAccess — Zend Error
                    // (ResourceBundle has read_dimension only; #25145).
                    $catchFrame = $this->dispatchVmError(
                        'Cannot use object of type ' . $object->class->name . ' as array',
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    return null;
                }
                [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforcePropertyName($propName, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->ensureLazyObjectInitialized($object, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $object = VM\LazyObjectSupport::getLazyInstance($object);
                if (!$op->issetForCoalesceAssign) {
                    $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($object, $propName, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                }
                $dst->bool(
                    $op->issetForCoalesceAssign
                        ? $this->objectPropertyIsSetForCoalesceAssign($object, $propName, $frame)
                        : $this->objectPropertyIsSet($object, $propName, $frame)
                );
                return null;
            }
            if (Variable::TYPE_STRING === $container->type) {
                if ($op->issetOnProperty) {
                    $dst->bool(false);
                    return null;
                }
                $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                $dst->bool(Variable::stringOffsetIsSetFromDim(
                    $container,
                    $frame->scope[$op->arg3],
                    $this->context->errors,
                    $this->context,
                    $frame,
                    $scriptFile
                ));
                return null;
            }
            $dst->bool(false);
            return null;
        }
        $value = $frame->scope[$op->arg2]->resolveIndirect();
        $dst->bool(
            !$value->isUndefined()
            && Variable::TYPE_NULL !== $value->type
        );
        return null;
    }
}
