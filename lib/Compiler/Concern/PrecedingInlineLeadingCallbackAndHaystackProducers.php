<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * CFG children op-index cache and leading-callback / haystack inline producers
 * (#36387 / prior #36147).
 *
 * Extracted from {@see PrecedingInlineCallArgProducers} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see ensureCfgChildrenOpIndicesBuilt} through
 * {@see trailingInlineFuncCallHaystackBeforeCfgCall}). Dead-void / dim-fetch
 * helpers remain in {@see PrecedingInlineDeadVoidAndDimFetchCallArgSlots}.
 *
 * Mirrors php-src Zend/zend_compile.c ARG_SEND ordering for callback-first
 * array_map/array_filter/array_walk haystacks — move-only; no behavior change.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as PrecedingInlineCallArgProducers).
 */
trait PrecedingInlineLeadingCallbackAndHaystackProducers
{
    /**
     * One O(n) pass over cfg block children indexes every op — avoids ~80 independent
     * O(n) linear scans per compileCallArgSends (#36224).
     *
     * Indexes by real array keys so sparse php-cfg children lists (holes after stmt
     * rewrites) do not poison {@see cfgCallOpIndexCache} with dense 0..count-1 slots
     * that later TypeError on `$children[$i]` (#36387 FinalClassConstCheck).
     *
     * @param list<Op> $cfgChildren
     */
    private function ensureCfgChildrenOpIndicesBuilt(array $cfgChildren, ?CfgBlock $cfgBlock = null): void
    {
        if ([] === $cfgChildren) {
            return;
        }
        $first = $cfgChildren[array_key_first($cfgChildren)];
        $mapKey = null !== $cfgBlock
            ? (string) spl_object_id($cfgBlock)
            : 'c_' . spl_object_id($first);
        $count = \count($cfgChildren);
        $prev = $this->cfgChildrenOpIndexBuiltCount[$mapKey] ?? 0;
        if ($prev === $count) {
            return;
        }
        foreach ($cfgChildren as $i => $child) {
            if ($child instanceof Op) {
                $this->cfgCallOpIndexCache[spl_object_id($child)] = $i;
            }
        }
        $this->cfgChildrenOpIndexBuiltCount[$mapKey] = $count;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function cfgCallOpIndexInChildren(array $cfgChildren, Op $callOp, ?CfgBlock $cfgBlock = null): ?int
    {
        $this->ensureCfgChildrenOpIndicesBuilt($cfgChildren, $cfgBlock);

        return $this->cfgCallOpIndexCache[spl_object_id($callOp)] ?? null;
    }

    /**
     * array_map(intval(...), str_split(...)) — php-cfg emits callback before nested haystack FuncCalls (#15487).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op>       $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function prependLeadingCallbackFirstInlineProducer(
        array $producers,
        array $cfgChildren,
        Op $callOp
    ): array {
        if (!($callOp instanceof Op\Expr\FuncCall || $callOp instanceof Op\Expr\NsFuncCall)) {
            return $producers;
        }
        $funcName = $this->resolveInlineCallArgFuncName($callOp);
        if (0 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return $producers;
        }
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                return $producers;
            }
        }
        $hasFuncCallProducer = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $hasFuncCallProducer = true;
                break;
            }
        }
        if (!$hasFuncCallProducer) {
            return $producers;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex || $callIndex < 2) {
            return $producers;
        }
        $haystackFuncCallIndex = $callIndex;
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            foreach ($cfgChildren as $i => $child) {
                if ($child === $producer && $i < $haystackFuncCallIndex) {
                    $haystackFuncCallIndex = $i;
                }
            }
        }
        $leading = null;
        $outerHaystack = $cfgChildren[$haystackFuncCallIndex] ?? null;
        for ($i = $haystackFuncCallIndex - 1; $i >= 0; --$i) {
            $candidate = $cfgChildren[$i] ?? null;
            if (!$candidate instanceof Op\Expr) {
                break;
            }
            if ($candidate instanceof Op\Expr\ArrowFunction
                || $candidate instanceof Op\Expr\Closure
                || $candidate instanceof Op\Expr\FirstClassCallable) {
                $leading = $candidate;
                break;
            }
            if ($candidate instanceof Op\Expr\FuncCall || $candidate instanceof Op\Expr\NsFuncCall) {
                $nestedInHaystack = ($outerHaystack instanceof Op\Expr\FuncCall || $outerHaystack instanceof Op\Expr\NsFuncCall)
                    && $this->isAdjacentNestedFuncCallProducer(
                        $candidate,
                        $outerHaystack,
                        $i,
                        $haystackFuncCallIndex
                    );
                if (
                    \in_array($candidate, $producers, true)
                    || $this->isNestedCallArgProducerForConsumer(
                        $candidate,
                        $callOp,
                        $i,
                        $callIndex,
                        $cfgChildren
                    )
                    || $nestedInHaystack
                ) {
                    continue;
                }

                break;
            }
            if (
                $candidate instanceof Op\Expr\ConstFetch
                && (
                    \in_array($candidate, $producers, true)
                    || $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg($candidate, $i, $callIndex, $cfgChildren)
                )
            ) {
                // array_map(intval(...), str_split(str_repeat('12', 1))) — skip haystack literal prelude (#16279).
                continue;
            }
            if ($this->isInlineExprCallArgProducer($candidate)) {
                break;
            }
        }
        if (!$leading instanceof Op\Expr\ArrowFunction
            && !$leading instanceof Op\Expr\Closure
            && !$leading instanceof Op\Expr\FirstClassCallable) {
            return $producers;
        }

        array_unshift($producers, $leading);

        return $producers;
    }

    /**
     * True when an Assign's result is consumed between the assign and a later inline call (#16279).
     *
     * @param list<Op> $cfgChildren
     */
    private function assignPrecedesAndFeedsInlineCallChain(
        Op\Expr\Assign $assign,
        int $assignIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $assign->result) {
            return false;
        }
        for ($j = $assignIndex + 1; $j < $consumerIndex; ++$j) {
            $later = $cfgChildren[$j] ?? null;
            if ($later instanceof Op\Expr && $this->cfgExprUsesOperand($later, $assign->result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_map(intval(...), f()) / array_filter(f(), is_numeric(...)) — callback before nested haystack (#15487, #15490).
     */
    private function leadingCallbackFirstInlineProducerBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if ($this->inlineClosureArrayPairCallbackArgIndex($funcName) < 0) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if (!$prev instanceof Op) {
                continue;
            }
            if ($prev instanceof Op\Expr\ArrowFunction
                || $prev instanceof Op\Expr\Closure
                || $prev instanceof Op\Expr\FirstClassCallable) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                    return null;
                }
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                return null;
            }
        }

        return null;
    }

    /**
     * array_walk(new C(...), fn(...)) — inline New_ subject before trailing closure callback (#17504).
     */
    private function leadingInlineNewBeforeCallbackBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr\New_
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($this->resolveInlineCallArgFuncName($cfgCallOp))) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $callback = $block->orig->children[$callIndex - 1] ?? null;
        if (!$callback instanceof Op\Expr\Closure && !$callback instanceof Op\Expr\ArrowFunction) {
            return null;
        }
        for ($i = $callIndex - 2; $i >= 0; --$i) {
            $prev = $block->orig->children[$i];
            if ($prev instanceof Op\Expr\New_) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Array_ || $prev instanceof Op\Expr\ConstFetch) {
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * array_map(intval(...), str_split(...)) — haystack FuncCall after leading FCC/closure (#15487, #15961).
     */
    private function leadingCallbackFirstHaystackFuncCallBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if (0 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $skippedCallback = false;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if (!$prev instanceof Op) {
                continue;
            }
            if (
                !$skippedCallback
                && ($prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\FirstClassCallable)
            ) {
                $skippedCallback = true;
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                    return null;
                }
                continue;
            }
            if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                return null;
            }
        }

        return null;
    }

    /**
     * array_filter(str_split(...), is_numeric(...)) — haystack FuncCall immediately before trailing FCC (#15490).
     *
     * Must not treat a prior sibling consumer (e.g. var_dump(...)) as the haystack when arg 0 is a
     * named CV — that wires null/void into the second expression-position call (#27344, #17989).
     */
    private function trailingInlineFuncCallHaystackBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        if ($haystackArg instanceof Operand && $this->isNamedVariableOperand($haystackArg)) {
            // array_filter($b, fn|/string) — real CV haystack, not a hoisted sibling FuncCall (#27344).
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $skippedCallback = false;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i];
            if (
                !$skippedCallback
                && ($prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\FirstClassCallable)
            ) {
                $skippedCallback = true;
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                // First FuncCall after skipping the callback only — inline haystack (#15490).
                // Named CV haystacks already returned null above (#27344).
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                return null;
            }
            // Array_ / other producers: haystack is not a trailing FuncCall — stop. Falling through
            // would skip past the real haystack Array_ to an older var_dump (#27344, #27347).
            return null;
        }

        return null;
    }
}
