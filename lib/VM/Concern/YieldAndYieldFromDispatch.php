<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_YIELD / TYPE_YIELD_FROM dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_generators.c zend_generator_get_child /
 * zend_generator_resume; Zend/zend_vm_def.h ZEND_YIELD / ZEND_YIELD_FROM).
 * Helpers live in {@see GeneratorForeachAndYieldFrom}. Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait YieldAndYieldFromDispatch
{
    /**
     * Execute YIELD / YIELD_FROM for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeYieldAndYieldFromDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_YIELD:
            $gen = $this->findGeneratorState($frame);
            if (null === $gen) {
                throw new \LogicException('yield outside generator function');
            }
            if (null !== $op->arg2) {
                if (isset($frame->scope[$op->arg2])) {
                    if ($gen->yieldsByReference()) {
                        $gen->publishCurrentValueByRef($frame->scope[$op->arg2]);
                    } else {
                        $gen->publishCurrentValue($frame->scope[$op->arg2]->resolveIndirect());
                    }
                } elseif (isset($frame->block->constants[$op->arg2])) {
                    $gen->publishCurrentValue($frame->block->constants[$op->arg2]);
                } else {
                    $gen->clearCurrentValue();
                }
            } else {
                $gen->currentValue->null();
                $gen->currentSnapshot->null();
                $gen->hasCurrent = true;
            }
            if (null !== $op->arg3) {
                if (isset($frame->scope[$op->arg3])) {
                    $gen->currentKey->duplicateFrom($frame->scope[$op->arg3]->resolveIndirect());
                    $gen->noteExplicitYieldKey($gen->currentKey);
                } elseif (isset($frame->block->constants[$op->arg3])) {
                    $gen->currentKey->duplicateFrom($frame->block->constants[$op->arg3]);
                    $gen->noteExplicitYieldKey($gen->currentKey);
                } else {
                    $gen->currentKey->int($gen->takeNextAutoKey());
                }
            } else {
                $gen->currentKey->int($gen->takeNextAutoKey());
            }
            if (null !== $op->arg1) {
                $gen->yieldResultSlot = $op->arg1;
            }
            if (null === $op->arg2) {
                $gen->hasCurrent = true;
            }
            $gen->frame = $frame;
            $frame->generatorYield = true;

            return null;
        case OpCode::TYPE_YIELD_FROM:
            $gen = $this->findGeneratorState($frame);
            if (null === $gen) {
                throw new \LogicException('yield from outside generator function');
            }
            if (null === $op->arg2 || !isset($frame->scope[$op->arg2])) {
                throw new \LogicException('yield from missing container operand');
            }
            if (!$gen->yieldFromActive) {
                $container = $frame->scope[$op->arg2]->resolveIndirect();
                $gen->yieldFromActive = true;
                $gen->yieldFromIteratorAdvance = false;
                if (Variable::TYPE_ARRAY === $container->type) {
                    $gen->yieldFromContainer->copyFrom($container);
                    $container->toArray()->iterReset();
                } elseif ($this->variableIsGenerator($container)) {
                    $gen->yieldFromContainer->copyFrom($container);
                    $container->toObject()->generatorState->rewind();
                } elseif (Variable::TYPE_OBJECT === $container->type) {
                    if (!$this->yieldFromContainerIsTraversable($container)) {
                        $this->throwYieldFromInvalidContainer($container);
                    }
                    $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                    $gen->yieldFromContainer->copyFrom($iterable);
                    if ($this->variableIsGenerator($iterable)) {
                        $iterable->toObject()->generatorState->rewind();
                    } else {
                        $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                    }
                } else {
                    $this->throwYieldFromInvalidContainer($container);
                }
            }
            $container = $gen->yieldFromContainer->resolveIndirect();
            if (Variable::TYPE_ARRAY === $container->type) {
                if ($container->toArray()->iterValid()) {
                    $gen->currentKey->copyFrom($container->toArray()->iterCurrentKey());
                    $gen->publishCurrentValue($container->toArray()->iterCurrentValue(false));
                    $gen->frame = $frame;
                    $frame->pos--;
                    $frame->generatorYield = true;

                    return null;
                }
                $this->completeYieldFromDelegation($gen, $frame, $op, null);

                return null;
            }
            if ($this->variableIsGenerator($container)) {
                $inner = $container->toObject()->generatorState;
                // Zend yield-from: rewind leaves inner on opening yield; do not advance past it (#23813, #23713).
                if ($inner->hasCurrent && !$inner->done && !$inner->foreachNeedsAdvance) {
                    $gen->currentKey->copyFrom($inner->currentKey);
                    $gen->publishCurrentValue($inner->currentSnapshot);
                    $inner->foreachNeedsAdvance = true;
                    $gen->frame = $frame;
                    $frame->pos--;
                    $frame->generatorYield = true;

                    return null;
                }
                try {
                    $innerAdvanced = $this->advanceGeneratorIteration($inner);
                } catch (VM\GeneratorUncaughtThrow $e) {
                    // Zend: inner throw at yield-from is catchable in the outer generator (#32102).
                    $gen->yieldFromActive = false;
                    $gen->yieldFromIteratorAdvance = false;
                    $catchFrame = $this->dispatchEngineThrow($frame, $e->thrown);
                    if (null !== $catchFrame) {
                        $catchFrame->generatorState = $gen;
                        $gen->frame = $catchFrame;

                        return $catchFrame;
                    }

                    return null;
                }
                if ($innerAdvanced) {
                    $gen->currentKey->copyFrom($inner->currentKey);
                    $gen->publishCurrentValue($inner->currentSnapshot);
                    $inner->foreachNeedsAdvance = true;
                    $gen->frame = $frame;
                    $frame->pos--;
                    $frame->generatorYield = true;

                    return null;
                }
                $delegatedReturn = $inner->hasReturned ? $inner->returnValue : null;
                $this->completeYieldFromDelegation($gen, $frame, $op, $delegatedReturn);

                return null;
            }
            if (Variable::TYPE_OBJECT === $container->type) {
                if ($gen->yieldFromIteratorAdvance) {
                    $this->invokeForeachInstanceMethod($frame, $container, 'next');
                }
                $valid = $this->invokeForeachInstanceMethod($frame, $container, 'valid');
                if ($valid->toBool()) {
                    $gen->currentKey->copyFrom(
                        $this->invokeForeachInstanceMethod($frame, $container, 'key')
                    );
                    $gen->publishCurrentValue(
                        $this->invokeForeachInstanceMethod($frame, $container, 'current')
                    );
                    $gen->yieldFromIteratorAdvance = true;
                    $gen->frame = $frame;
                    $frame->pos--;
                    $frame->generatorYield = true;

                    return null;
                }
                $this->completeYieldFromDelegation($gen, $frame, $op, null);

                return null;
            }
            $this->throwYieldFromInvalidContainer($container);
        }

        return null;
    }
}
