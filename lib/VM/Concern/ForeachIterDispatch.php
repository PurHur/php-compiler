<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakMapIterator;
use PHPCompiler\VM\WeakRefSupport;

/**
 * VM TYPE_ITER_RESET / TYPE_ITER_VALID / TYPE_ITER_KEY / TYPE_ITER_VALUE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner foreach-iterator case bodies
 * (php-src Zend/zend_vm_def.h ZEND_FE_RESET_R / ZEND_FE_RESET_RW / ZEND_FE_FETCH_R /
 * ZEND_FE_FETCH_RW; zend_execute.c / zend_interfaces.c Iterator protocol). Companion
 * helpers live in {@see GeneratorForeachAndYieldFrom}. Concern trait — same namespace
 * as parent so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ForeachIterDispatch
{
    /**
     * Execute TYPE_ITER_RESET for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIterResetDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        // Zend FE_RESET / CV fetch: Undefined variable E_WARNING before type check (#26148).
        $container = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg1)->resolveIndirect();
        unset($this->context->foreachInvalidSlots[$op->arg1]);
        if ($this->variableIsGenerator($container)) {
            unset($this->context->foreachObjectAdvance[$op->arg1]);
            unset($this->context->objectPropertyIterators[$op->arg1]);
            unset($this->context->weakMapIterators[$op->arg1]);
            $frame->iterators[$op->arg1] = $container;
            $this->context->foreachIterators[$op->arg1] = $container;
            try {
                $container->toObject()->generatorState->rewindForForeach();
            } catch (\Exception $e) {
                $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }

            return null;
        }
        if (Variable::TYPE_ARRAY === $container->type) {
            unset($this->context->foreachObjectAdvance[$op->arg1]);
            unset($this->context->objectPropertyIterators[$op->arg1]);
            unset($this->context->weakMapIterators[$op->arg1]);
            $this->bindArrayForeachIteratorContainer($frame, (int) $op->arg1, $container);

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            try {
                unset($this->context->objectPropertyIterators[$op->arg1]);
                unset($this->context->weakMapIterators[$op->arg1]);
                $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                $frame->iterators[$op->arg1] = $iterable;
                $this->context->foreachIterators[$op->arg1] = $iterable;
                if ($this->variableIsGenerator($iterable)) {
                    unset($this->context->foreachObjectAdvance[$op->arg1]);
                    try {
                        $iterable->toObject()->generatorState->rewindForForeach();
                    } catch (\Exception $e) {
                        $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            return $catchFrame;
                        }
                    }

                    return null;
                }
                $this->context->foreachObjectAdvance[$op->arg1] = false;
                $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');

                return null;
            } catch (\TypeError $e) {
                // Property-foreach fallback only for "not iterable" (#3234).
                // Return-type / other TypeErrors from getIterator() must reach userland (#19729).
                if (!str_contains($e->getMessage(), 'is not iterable')) {
                    $catchFrame = $this->dispatchVmTypeError($e, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return null;
                }
                unset($this->context->foreachObjectAdvance[$op->arg1]);
                if (WeakRefSupport::isWeakMap($container->toObject())) {
                    unset($this->context->objectPropertyIterators[$op->arg1]);
                    unset($this->context->weakMapIterators[$op->arg1]);
                    $iter = new WeakMapIterator($container->toObject());
                    $iter->reset();
                    $this->context->weakMapIterators[$op->arg1] = $iter;

                    return null;
                }
                $iter = new ObjectPropertyIterator($container->toObject(), $this, $frame);
                $iter->reset();
                $this->context->objectPropertyIterators[$op->arg1] = $iter;

                return null;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                // Iterator protocol throw (FilterIterator::accept, …) — do not re-wrap (#24286).
                return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
            } catch (\Exception $e) {
                // zend_interfaces.c — bad getIterator() return is Exception, not TypeError (#19729).
                $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return null;
            }
        }
        $this->warnForeachNonTraversable($container, $frame, $op);
        unset($this->context->foreachObjectAdvance[$op->arg1]);
        unset($this->context->objectPropertyIterators[$op->arg1]);
        unset($this->context->weakMapIterators[$op->arg1]);
        unset($this->context->foreachIterators[$op->arg1]);
        unset($frame->iterators[$op->arg1]);
        $this->context->foreachInvalidSlots[$op->arg1] = true;

        return null;
    }

    /**
     * Execute TYPE_ITER_VALID for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIterValidDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if ($this->isForeachInvalidSlot((int) $op->arg2)) {
            $frame->scope[$op->arg1]->bool(false);

            return null;
        }
        $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
        if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
            if ($this->context->foreachObjectAdvance[$op->arg2]) {
                $this->invokeForeachInstanceMethod($frame, $container, 'next');
            }
            $valid = $this->invokeForeachInstanceMethod($frame, $container, 'valid');
            $frame->scope[$op->arg1]->bool($valid->toBool());

            return null;
        }
        if ($this->variableIsGenerator($container)) {
            $catchFrame = $this->foreachAdvanceGenerator(
                $frame,
                $container->toObject()->generatorState,
                (int) $op->arg1
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                $frame->scope[$op->arg1]->bool(
                    $this->weakMapForeachIterator($op->arg2)->valid()
                );

                return null;
            }
            $frame->scope[$op->arg1]->bool(
                $this->objectForeachIterator($op->arg2)->valid()
            );

            return null;
        }
        if (Variable::TYPE_ARRAY !== $container->type) {
            // Literal scalars re-embed per block (RESET slot ≠ VALID slot), so
            // foreachInvalidSlots from FE_RESET is missed — treat as empty (#23452).
            $frame->scope[$op->arg1]->bool(false);

            return null;
        }
        $frame->scope[$op->arg1]->bool($container->toArray()->iterValid());

        return null;
    }

    /**
     * Execute TYPE_ITER_KEY for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIterKeyDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if ($this->isForeachInvalidSlot((int) $op->arg2)) {
            return null;
        }
        $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
        if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
            $key = $this->invokeForeachInstanceMethod($frame, $container, 'key');
            $frame->scope[$op->arg1]->copyFrom($key);

            return null;
        }
        if ($this->variableIsGenerator($container)) {
            $frame->scope[$op->arg1]->copyFrom(
                $container->toObject()->generatorState->currentKey
            );

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                $frame->scope[$op->arg1]->copyFrom(
                    $this->weakMapForeachIterator($op->arg2)->currentKey()
                );

                return null;
            }
            $frame->scope[$op->arg1]->copyFrom(
                $this->objectForeachIterator($op->arg2)->currentKey()
            );

            return null;
        }
        if (Variable::TYPE_ARRAY !== $container->type) {
            // Non-traversable: FE_RESET warned; no key fetch (#23452 / zend_vm_def.h).
            return null;
        }
        $frame->scope[$op->arg1]->copyFrom($container->toArray()->iterCurrentKey());

        return null;
    }

    /**
     * Execute TYPE_ITER_VALUE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIterValueDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if ($this->isForeachInvalidSlot((int) $op->arg2)) {
            return null;
        }
        $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
        if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
            if ((bool) $op->arg3) {
                // Zend FE_RESET_RW allow-list: array-backed SPL iterators (#19444).
                $iterObj = $container->toObject();
                if (VM\SplArraySupport::allowsForeachByRef($iterObj)) {
                    $byRef = VM\SplArraySupport::foreachCurrentByRef($iterObj);
                    if (null !== $byRef) {
                        $frame->scope[$op->arg1]->indirect($byRef);
                        $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                        $this->context->foreachObjectAdvance[$op->arg2] = true;

                        return null;
                    }
                }
                if (VM\SplArraySupport::allowsRecursiveArrayIteratorForeachByRef($iterObj)) {
                    $byRef = VM\SplArraySupport::recursiveArrayIteratorForeachCurrentByRef($iterObj);
                    if (null !== $byRef) {
                        $frame->scope[$op->arg1]->indirect($byRef);
                        $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                        $this->context->foreachObjectAdvance[$op->arg2] = true;

                        return null;
                    }
                }
                $catchFrame = $this->dispatchVmError(
                    'An iterator cannot be used with foreach by reference',
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return null;
            }
            $value = $this->invokeForeachInstanceMethod($frame, $container, 'current');
            $frame->scope[$op->arg1]->copyFrom($value);
            $this->context->foreachObjectAdvance[$op->arg2] = true;

            return null;
        }
        if ($this->variableIsGenerator($container)) {
            if ((bool) $op->arg3) {
                $genState = $container->toObject()->generatorState;
                if (!$genState->yieldsByReference()) {
                    $catchFrame = $this->dispatchVmEngineException(
                        \PHPCompiler\JIT\GeneratorHelper::FOREACH_GENERATOR_BYREF_ERROR,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return null;
                }
                $frame->scope[$op->arg1]->indirectAsPhpReference(
                    $genState->currentValue->byRefTarget()
                );
                $this->markScopeSlotInitialized($frame, (int) $op->arg1);

                return null;
            }
            $frame->scope[$op->arg1]->copyFrom(
                $container->toObject()->generatorState->currentValue
            );

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            $byRef = (bool) $op->arg3;
            if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                $iter = $this->weakMapForeachIterator($op->arg2);
                if ($byRef) {
                    $frame->scope[$op->arg1]->indirectAsPhpReference($iter->currentValue(true));
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                } else {
                    $frame->scope[$op->arg1]->assignForeachByValue($iter->currentValue(false));
                }

                return null;
            }
            if ($byRef) {
                try {
                    $frame->scope[$op->arg1]->indirectAsPhpReference(
                        $this->objectForeachIterator($op->arg2)->currentValue(true)
                    );
                } catch (VM\PropertyHookRefWriteSignal $signal) {
                    return $signal->catchFrame;
                }
                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
            } else {
                try {
                    $frame->scope[$op->arg1]->assignForeachByValue(
                        $this->objectForeachIterator($op->arg2)->currentValue(false)
                    );
                } catch (VM\PropertyHookRefWriteSignal $signal) {
                    return $signal->catchFrame;
                }
            }

            return null;
        }
        if (Variable::TYPE_ARRAY !== $container->type) {
            // Non-traversable: FE_RESET warned; no value fetch (#23452 / zend_vm_def.h).
            return null;
        }
        $byRef = (bool) $op->arg3;
        if ($byRef) {
            $this->rebindArrayForeachToLiveContainer($frame, (int) $op->arg2);
            $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
            $frame->scope[$op->arg1]->indirectAsPhpReference(
                $container->toArray()->iterCurrentValue(true)
            );
            $this->markScopeSlotInitialized($frame, (int) $op->arg1);
        } else {
            $frame->scope[$op->arg1]->assignForeachByValue(
                $container->toArray()->iterCurrentValue(false)
            );
        }

        return null;
    }
}
