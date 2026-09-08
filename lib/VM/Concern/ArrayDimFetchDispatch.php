<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ARRAY_DIM_FETCH / TYPE_ARRAY_DIM_FETCH_WRITE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner dim-fetch case body
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_DIM_R/W / zend_fetch_dimension_* /
 * ArrayAccess offsetGet/offsetSet). Concern trait — same namespace as parent
 * so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|null Frame → restart runFramesInner; null → opcode complete.
 */
trait ArrayDimFetchDispatch
{
    /**
     * Execute ARRAY_DIM_FETCH / ARRAY_DIM_FETCH_WRITE for the current opcode.
     *
     * @return Frame|null
     */
    private function executeArrayDimFetchDispatch(Frame $frame, OpCode $op): ?Frame
    {
        $arg1 = $frame->scope[$op->arg1];
        $containerSlot = $frame->scope[$op->arg2];
        $container = $containerSlot->resolveIndirect();
        $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
        $fetchIs = !$forWrite && $op->arrayDimFetchIs;
        $catchFrame = $this->rejectMagicGetIndirectModify($containerSlot, $forWrite, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if ($container->isArrayAccessOffset()) {
            // Nested dim through ArrayAccess (#5460 / #20005): materialize via
            // offsetGet. Objects (SimpleXMLElement, ArrayObject, …) accept further
            // write_dimension; arrays returned by value cannot be written back.
            try {
                $materialized = $container->readArrayAccessOffsetValue();
            } catch (VM\ArrayAccessOffsetSignal $signal) {
                return $signal->catchFrame;
            }
            if ($forWrite || is_null($op->arg3)) {
                if (Variable::TYPE_OBJECT === $materialized->type) {
                    $container = $materialized;
                } else {
                    $this->context->errors->indirectModificationOfOverloadedElement(
                        $container->arrayAccessOffsetClassName(),
                        $this->context,
                        $frame,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null
                    );
                    $arg1->null();
                    return null;
                }
            } else {
                $container = $materialized;
            }
        }
        // ZEND_FETCH_DIM_W: null/undefined/false containers auto-vivify (#21992, #22650).
        // false→[] also emits E_DEPRECATED since PHP 8.1 (zend_execute.c / #22828).
        if ($forWrite && TypeCheck::isNullContainerForDimAutovivify($container)) {
            if (TypeCheck::isFalseContainerForDimAutovivify($container)) {
                $this->context->errors->internalDeprecated(
                    TypeCheck::FALSE_TO_ARRAY_DEPRECATED_MESSAGE,
                    $this->context,
                    $frame,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                );
            }
            $container->array(new HashTable());
            // Zend defines the CV on FETCH_DIM_W — mark script globals / locals so a
            // later bare read does not emit Undefined variable (#29146, re-#21992).
            $this->markScopeSlotInitialized($frame, (int) $op->arg2);
        }
        $isGlobals = Variable::TYPE_ARRAY === $container->type
            && $this->context->isGlobalsTable($container);
        if ($forWrite && Variable::TYPE_ARRAY === $container->type && !$isGlobals) {
            $container->separateArrayForWrite();
            $container = $containerSlot->resolveIndirect();
        }
        if (is_null($op->arg3)) {
            if (TypeCheck::isScalarUsedAsArray($container)) {
                $catchFrame = $this->dispatchVmError(
                    TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE,
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            if ($container->type !== Variable::TYPE_ARRAY) {
                if (Variable::TYPE_STRING === $container->type) {
                    $catchFrame = $this->dispatchVmError(
                        TypeCheck::STRING_APPEND_UNSUPPORTED_MESSAGE,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    return null;
                }
                if (
                    Variable::TYPE_OBJECT === $container->type
                    && $this->objectImplementsArrayAccess($container->toObject())
                ) {
                    if (!$forWrite) {
                        throw new \LogicException('[] is only supported for arrays');
                    }
                    $object = $container->toObject();
                    $nullKey = new Variable(Variable::TYPE_NULL);
                    $nullKey->null();
                    $dim = new Variable();
                    $dim->arrayAccessDimension(
                        new VM\ArrayAccessDimension($this, $object, $nullKey, $frame)
                    );
                    $arg1->indirect($dim);
                    return null;
                }
                if (
                    Variable::TYPE_OBJECT === $container->type
                    && !$this->objectImplementsArrayAccess($container->toObject())
                ) {
                    $className = $container->toObject()->class->name;
                    $catchFrame = $this->dispatchVmError(
                        'Cannot use object of type ' . $className . ' as array',
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    return null;
                }
                throw new \LogicException('[] is only supported for arrays');
            }
            try {
                $appendCell = $container->toArray()->append(new Variable);
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $arg1->indirect($appendCell);
            if (null !== $appendCell) {
                $this->markPersistentHashTableBucketIfNeeded($containerSlot, $appendCell);
            }
            $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
            return null;
        }
        // Literal dim keys live in block->constants; scope[slot] may be a CV that
        // aliased the same integer and was later assigned an array (#36380 /
        // Parsedown `$this->DefinitionData['Reference'][$id] = $Data`).
        $arg3 = $this->readDimKeyOperand($frame, (int) $op->arg3);
        if (Variable::TYPE_STRING_OFFSET === $container->type) {
            $catchFrame = $this->dispatchVmError(
                'Cannot use string offset as an array',
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if ($container->type === Variable::TYPE_STRING) {
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            try {
                $byteIndex = Variable::stringOffsetIndexFromDim(
                    $arg3,
                    $this->context->errors,
                    $this->context,
                    $frame,
                    $scriptFile
                );
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            if ($forWrite) {
                $offset = new Variable(Variable::TYPE_STRING_OFFSET);
                $offset->stringOffset(
                    $container,
                    $byteIndex,
                    $this->context->errors,
                    $this->context,
                    $frame,
                    $scriptFile
                );
                $arg1->indirect($offset);
                return null;
            }
            $readShell = new Variable(Variable::TYPE_STRING_OFFSET);
            $readShell->stringOffset(
                $container,
                $byteIndex,
                $this->context->errors,
                $this->context,
                $frame,
                $scriptFile
            );
            $arg1->string($readShell->toString());
        } elseif ($container->type === Variable::TYPE_ARRAY) {
            if ($this->context->isGlobalsTable($container)) {
                if (!$forWrite && !$fetchIs && Variable::TYPE_STRING === $arg3->type
                    && !$this->context->globalsTableOffsetIsSet($arg3)) {
                    $this->context->errors->undefinedGlobalVariable(
                        $arg3->toString(),
                        $this->context,
                        $frame,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null
                    );
                }
                $arg1->indirect($this->context->globalsTableOffsetFetch($arg3, $forWrite));
                return null;
            }
            $table = $container->toArray();
            try {
                // ++/-- / += FETCH_DIM_W: warn on missing key then treat as null (#30078, #31991).
                $forRwOp = $forWrite && (
                    $this->propertyFetchDestUsedAsIncDec($frame, $op)
                    || $this->propertyFetchDestUsedAsCompoundAssign($frame, $op)
                    || $this->propertyFetchDestUsedAsDimRwContainer($frame, $op)
                );
                if (
                    (!$forWrite && !$fetchIs || $forRwOp)
                    && !$table->keyExists($arg3, false, $frame, false)
                ) {
                    $this->context->errors->undefinedArrayKey(
                        $arg3,
                        $this->context,
                        $frame,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null
                    );
                }
                // Coalesce left read: isset already emitted float→int DEP (#29664).
                $emitFloatKeyDep = !$op->arrayDimFetchSkipFloatKeyDeprecation;
                $dimCell = $table->findVariable(
                    $arg3,
                    $forWrite,
                    $this->context,
                    $frame,
                    $emitFloatKeyDep
                );
                $arg1->indirect($dimCell);
                if ($forWrite && null !== $dimCell) {
                    $this->markPersistentHashTableBucketIfNeeded($containerSlot, $dimCell);
                }
                if ($forWrite) {
                    $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
                }
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
        } elseif (
            Variable::TYPE_OBJECT === $container->type
            && null !== ($dimHandler = $this->context->findObjectDimensionHandler($container->toObject()))
        ) {
            // Extension-owned read_dimension (DOM collections / ResourceBundle; #20311, #25145, #36204).
            // Not ArrayAccess — writes stay "Cannot use object of type … as array".
            if ($forWrite) {
                if ($dimHandler->rejectWrite) {
                    $className = $container->toObject()->class->name;
                    $catchFrame = $this->dispatchVmError(
                        'Cannot use object of type ' . $className . ' as array',
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                }
                return null;
            }
            try {
                ($dimHandler->read)($container->toObject(), $arg3, $arg1);
            } catch (\ValueError $e) {
                $catchFrame = $this->dispatchVmValueError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            } catch (\TypeError $e) {
                // Dom\TokenList illegal offset (php-src token_list.c; #23006).
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
        } elseif (
            Variable::TYPE_OBJECT === $container->type
            && $this->objectImplementsArrayAccess($container->toObject())
        ) {
            $object = $container->toObject();
            if ($forWrite) {
                $dim = new Variable();
                $dim->arrayAccessDimension(new VM\ArrayAccessDimension($this, $object, $arg3, $frame));
                $arg1->indirect($dim);
            } else {
                $readOut = new Variable();
                $catchFrame = $this->invokeArrayAccessOffsetGet($object, $arg3, $frame, $readOut);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $arg1->copyFrom($readOut);
            }
        } else {
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            // Resource as array subject — Zend Warning/null on read, scalar Error on write (#30028).
            if (
                Variable::TYPE_OBJECT === $container->type
                && VM\ResourceSupport::isResourceObject($container->toObject())
            ) {
                if ($forWrite) {
                    $catchFrame = $this->dispatchVmError(
                        TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    return null;
                }
                if (!$fetchIs) {
                    $this->context->errors->arrayOffsetOnResource(
                        $this->context,
                        $frame,
                        $scriptFile
                    );
                }
                $arg1->null();
                return null;
            }
            if (!$forWrite && TypeCheck::isScalarNonContainerDimRead($container)) {
                if (!$fetchIs) {
                    $resolved = $container->resolveIndirect();
                    $this->context->errors->arrayOffsetOnNonContainer(
                        VM\ErrorReporter::arrayOffsetTypeLabel($resolved),
                        $this->context,
                        $frame,
                        $scriptFile
                    );
                }
                $arg1->null();
                return null;
            }
            if (
                Variable::TYPE_OBJECT === $container->type
                && !$this->objectImplementsArrayAccess($container->toObject())
            ) {
                $className = $container->toObject()->class->name;
                $catchFrame = $this->dispatchVmError(
                    'Cannot use object of type ' . $className . ' as array',
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            if (TypeCheck::isScalarUsedAsArray($container)) {
                $catchFrame = $this->dispatchVmError(
                    TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE,
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            throw new \LogicException('Illegal offset');
        }

        return null;
    }
}
