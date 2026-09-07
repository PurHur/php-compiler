<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Exact hoisted call-arg slots and closure/FCC/comparator matching (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers contiguous sibling multi-arg producer chains, exact Temporary writer lookup
 * for hoisted args (#23354), and Closure/Arrow/FCC/usort-family comparator matching.
 * Nested/positional/trailing inline `new` ctor-arg wiring lives in
 * {@see InlineNewCallArgProducers}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait ExactHoistedAndInlineNewCallArgProducers
{
    private function isInlineExprCallArgConsumer(Op $op): bool
    {
        return $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\New_;
    }

    /** php-cfg f(g(), h()) sibling producers feeding a multi-arg call (#9463, #14828). */
    private function isSiblingMultiArgInlineCallConsumer(Op $consumer): bool
    {
        return $consumer instanceof Op\Expr\FuncCall
            || $consumer instanceof Op\Expr\NsFuncCall
            || $consumer instanceof Op\Expr\MethodCall
            || $consumer instanceof Op\Expr\StaticCall;
    }

    /**
     * First hoisted producer for this multi-arg consumer — contiguous chain only (#16254).
     *
     * var_dump(strlen(), substr()); … var_dump(ftell(), fgetc()) must not treat strlen as the
     * chain start for the second consumer; stmt-level fseek/fwrite between chains stops the scan.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstContiguousSiblingMultiArgProducerIndex(
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): ?int {
        if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
            return null;
        }
        $first = null;
        for ($j = $consumerIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                $child instanceof Op\Expr\FuncCall
                || $child instanceof Op\Expr\NsFuncCall
                || $child instanceof Op\Expr\MethodCall
                || $child instanceof Op\Expr\StaticCall
            ) {
                if ($this->isSiblingMultiArgFuncCallProducer($child, $consumer, $j, $consumerIndex, $cfgChildren)) {
                    $first = $j;
                    continue;
                }
                // Not part of this contiguous chain — do not keep scanning the whole block
                // (nested call stmts were O(n²) isSiblingMultiArg probes — #36387 / #36224).
                break;
            } elseif ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif ($child instanceof Op\Expr\Array_) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif (null !== $first) {
                break;
            } else {
                break;
            }
        }

        return $first;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function countContiguousSiblingMultiArgProducers(
        int $firstProducer,
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): int {
        $count = 0;
        for ($j = $firstProducer; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($this->isSiblingMultiArgFuncCallProducer($child, $consumer, $j, $consumerIndex, $cfgChildren)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<Op> $children
     */
    private function onlyInlineCallArgProducersBetweenIndices(array $children, int $fromIndex, int $toIndex): bool
    {
        if ($fromIndex >= $toIndex - 1) {
            return false;
        }
        for ($k = $fromIndex + 1; $k < $toIndex; ++$k) {
            $stmt = $children[$k];
            if (!$stmt instanceof Op\Expr || !$this->isInlineExprCallArgProducer($stmt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Producer statement for a specific hoisted call argument (#23354).
     *
     * The $prev heuristics below resolve a hoisted argument from children[$callIndex - 1] — the
     * statement immediately before the call, which is only ever the TRAILING argument's producer.
     * Applied to every index, that silently gave each argument the last one's value:
     * f($x + 1, $x + 2) printed "12 12" and t2($r['a'], $r['b']) printed "BBB|BBB".
     *
     * No positional guessing is needed. php-cfg keeps the link: the hoisted argument temporary is a
     * distinct Operand from the producer's ->result (which is why slotForOperand($arg) misses), but
     * it records that producer as its sole writer. args[$argIndex]->ops[0] therefore names the
     * producer exactly, for every producer kind and any mix of them.
     *
     * Restricted to dead inline temporaries with a single writer — a named variable or a
     * multiply-written temp is not a hoisted argument and stays with the existing paths.
     */
    private function inlineProducerForHoistedCallArgIndex(
        array $cfgChildren,
        Op $callOp,
        int $callIndex,
        int $argIndex
    ): ?Op\Expr {
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand\Temporary || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $writers = $callArg->ops ?? [];
        if (1 !== \count($writers)) {
            return null;
        }
        $producer = $writers[0];
        if (!$producer instanceof Op\Expr || !$this->isInlineExprCallArgProducer($producer)) {
            return null;
        }
        if (null === $producer->result) {
            return null;
        }
        // The producer must be a hoisted statement of this block, sitting before the call.
        $producerIndex = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            if (($cfgChildren[$i] ?? null) === $producer) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return null;
        }

        return $producer;
    }

    /**
     * Slot holding argument $argIndex's own hoisted producer, via php-cfg's exact link (#23354).
     *
     * The hoisted argument temporary is a distinct Operand from the producer's ->result — which is
     * why slotForOperand($arg) misses and the shape heuristics exist — but it records that producer
     * as its sole writer. Restricted to dead inline temporaries with exactly one writer, whose
     * producer is a hoisted statement of this block before the call.
     *
     * @param list<OpCode> $sends
     */
    private function exactHoistedCallArgProducerSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand\Temporary || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if (1 !== \count($callArg->ops ?? [])) {
            return null;
        }
        $producer = $callArg->ops[0];
        if (!$producer instanceof Op\Expr || null === $producer->result) {
            return null;
        }
        if (!$this->isInlineExprCallArgProducer($producer)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        // Indexed lookup — walking back to 0 re-scanned every prior statement (#36387).
        $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
        if (!\is_int($producerIndex) || $producerIndex >= $callIndex) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null === $slot) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $sends[] = $op;
            }
            $slot = $block->slotForOperand($producer->result);
        }

        return null !== $slot ? (string) $slot : null;
    }


    private function matchSingleClosureInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (
            !($producer instanceof Op\Expr\ArrowFunction)
            && !($producer instanceof Op\Expr\Closure)
        ) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $closureSlots = [];
        foreach ($callArgs as $idx => $arg) {
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if ($this->isNamedVariableOperand($arg)) {
                continue;
            }
            $closureSlots[] = $idx;
        }
        if (1 === count($closureSlots) && $closureSlots[0] === $argIndex) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        // array_filter($a, fn(...), ARRAY_FILTER_USE_*) — callback slot from builtin signature (#10232, #9154).
        if ($callbackArgIndex >= 0 && \count($callArgs) >= 3 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_filter/array_any inline array + fn — callback is arg 1, not arg 0 (#12721).
        if ($callbackArgIndex > 0 && 2 === \count($callArgs) && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_map(fn(...), $arr) — callback is arg 0 (#10651).
        if (
            0 === $callbackArgIndex
            && 2 === \count($callArgs)
            && 0 === $argIndex
            && \count($closureSlots) >= 1
            && 0 === $closureSlots[0]
        ) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }

        return null;
    }

    /** Inline strcmp(...) and other FCC comparators — last callback arg only (#13990, zend_closures.c). */
    private function matchSingleFirstClassCallableInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (!$producer instanceof Op\Expr\FirstClassCallable) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        if ($callbackArgIndex >= 0 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }
        if (1 === \count($callArgs) && 0 === $argIndex) {
            return $producer;
        }

        return null;
    }

    /** array_udiff* / usort* — comparator is the trailing call argument (ext/standard/array.c). */
    private function builtinUsesTrailingComparatorCallback(?string $funcName): bool
    {
        if (null === $funcName || '' === $funcName) {
            return false;
        }

        return \in_array(strtolower($funcName), [
            'usort',
            'uasort',
            'uksort',
            'array_udiff',
            'array_uintersect',
            'array_udiff_assoc',
            'array_uintersect_assoc',
            'array_udiff_uassoc',
            'array_uintersect_uassoc',
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_diff_ukey',
            'array_intersect_ukey',
        ], true);
    }

    /**
     * array_udiff(array_keys(...), array_keys(...), strcmp(...)) — FuncCall/FCC hoists (#13990).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchTrailingComparatorInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?string $funcName
    ): ?Op\Expr {
        if (!$this->builtinUsesTrailingComparatorCallback($funcName)) {
            return null;
        }
        $argCount = \count($callArgs);
        if ($argCount < 2) {
            return null;
        }
        $callbackArgIndex = $argCount - 1;
        $callbackProducer = null;
        $funcProducers = [];
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                $callbackProducer = $producer;
            } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                if ($this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($producer))) {
                    continue;
                }
                $funcProducers[] = $producer;
            }
        }
        if (null === $callbackProducer) {
            $callbackArg = $callArgs[$callbackArgIndex] ?? null;
            if (null !== $callbackArg && (
                $this->isEmbeddedCallLiteralArg($callbackArg)
                || !$this->callArgIsDeadInlineTemporary($callbackArg)
            )) {
                $funcArgIndex = 0;
                foreach ($callArgs as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if ($this->callArgIsDeadInlineTemporary($callArg)) {
                        if ($i === $argIndex) {
                            return $funcProducers[$funcArgIndex] ?? null;
                        }
                        ++$funcArgIndex;
                    }
                }

                return null;
            }

            return null;
        }
        if ($argIndex === $callbackArgIndex) {
            return $callbackProducer;
        }
        $funcArgIndex = 0;
        foreach ($callArgs as $i => $callArg) {
            if ($i >= $callbackArgIndex) {
                break;
            }
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                return $funcProducers[$funcArgIndex] ?? null;
            }
            ++$funcArgIndex;
        }

        return null;
    }

    /**
     * Hoisted FuncCall producers may supply a dead temp slot — not an unrelated named local (#9074).
     */
    private function namedCallArgMayUseFuncCallProducerResult(Op\Expr $producer, Operand $callArg): bool
    {
        if (!$this->isNamedVariableOperand($callArg)) {
            return true;
        }
        if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
            return true;
        }
        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
            return $this->funcCallExprByRefArgMatchesOperand($producer, $callArg);
        }

        return false;
    }

    /** True when a hoisted FuncCall temp is an operand of the consumer call (#8561). */
    private function inlineCallArgProducerFeedsConsumer(Op\Expr $producer, Op $consumer): bool
    {
        if (!property_exists($producer, 'result') || !property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $producerRoot = Block::cfgVarRoot($producer->result);
        foreach ($consumer->args as $callArg) {
            if ($callArg === $producer->result) {
                return true;
            }
            if ($this->operandsReferToSameVariable($callArg, $producer->result)) {
                return true;
            }
            if (null !== $producerRoot && Block::cfgVarRoot($callArg) === $producerRoot) {
                return true;
            }
        }

        return false;
    }
}
