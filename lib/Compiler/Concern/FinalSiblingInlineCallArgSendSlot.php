<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Final sibling ARG_SEND slot wiring (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineCallArgProducerSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see finalSiblingInlineCallArgSendSlot}).
 * Outer-sibling EXEC_RETURN helpers already live in
 * {@see OuterSiblingExecReturnAndHoistedConstFetchSlots} (#37020).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait FinalSiblingInlineCallArgSendSlot
{
    /**
     * var_dump($g(), $g()) — ARG_SEND must use FUNCCALL_EXEC_RETURN slots, not drifted operand temps (#16029).
     */
    private function finalSiblingInlineCallArgSendSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return null;
        }
        if (
            1 === $argIndex
            && 'substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, null) ?? '')
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex)) {
                $cfgChildren = $block->orig->children;
                for ($j = $callIndex - 1; $j >= 0; --$j) {
                    $scan = $cfgChildren[$j] ?? null;
                    if (
                        ($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall)
                        && $this->isNestedCallArgProducerForConsumer($scan, $cfgCallOp, $j, $callIndex, $cfgChildren)
                        && 0 === $this->siblingMultiArgFuncCallProducerTargetArgIndex($j, $callIndex, $cfgChildren)
                    ) {
                        return null;
                    }
                }
            }
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $constPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg($block, $cfgCallOp, $argIndex);
        if (null !== $constPreludeSlot) {
            return $constPreludeSlot;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        $deadInlineTempCount = 0;
        foreach ($cfgCallOp->args as $callArg) {
            if ($this->callArgIsDeadInlineTemporary($callArg)) {
                ++$deadInlineTempCount;
            }
        }
        $outer = $this->outerSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (\count($outer) >= $argCount) {
            $outer = \array_slice($outer, -$argCount);
        }
        if (
            \count($outer) === $argCount
            && $deadInlineTempCount === $argCount
        ) {
            $outerSlot = $this->outerSiblingInlineCallArgProducerExecReturnSlot($block, $cfgCallOp, $argIndex);
            if (null !== $outerSlot) {
                return $outerSlot;
            }
        }
        $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if ($siblingFuncCount < 2) {
            $nestedSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, $argIndex);
            if (null !== $nestedSlot) {
                return $nestedSlot;
            }

            return null;
        }
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            return null;
        }
        for ($j = $firstSibling; $j < $callIndex; ++$j) {
            $between = $block->orig->children[$j] ?? null;
            if ($between instanceof Op\Expr\Array_) {
                return null;
            }
        }
        $leadingEmbedded = 0;
        foreach ($cfgCallOp->args as $embeddedArg) {
            if ($this->isEmbeddedCallLiteralArg($embeddedArg)) {
                ++$leadingEmbedded;
                continue;
            }
            break;
        }
        // array_intersect(f(g()), f(g())) — map args to outer f() EXEC_RETURN slots, not inner g() (#15488, #16031).
        $outerSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, $argIndex);
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        // probe('label', in_array(..., g(), true)) — one hoisted arg maps to adjacent callee, not firstSibling (#16253).
        if (1 === $deadInlineTempCount && $callIndex > 0) {
            $adjacentIndex = $callIndex - 1;
            while ($adjacentIndex >= 0) {
                $adjacentSkip = $block->orig->children[$adjacentIndex] ?? null;
                if ($adjacentSkip instanceof Op\Expr\ConstFetch || $adjacentSkip instanceof Op\Expr\ClassConstFetch) {
                    --$adjacentIndex;
                    continue;
                }
                break;
            }
            $adjacentProducer = $block->orig->children[$adjacentIndex] ?? null;
            if (
                ($adjacentProducer instanceof Op\Expr\FuncCall || $adjacentProducer instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer(
                    $adjacentProducer,
                    $cfgCallOp,
                    $adjacentIndex,
                    $callIndex
                )
            ) {
                $adjacentTargetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $adjacentIndex,
                    $callIndex,
                    $block->orig->children
                );
                if (null === $adjacentTargetArg) {
                    $adjacentTargetArg = $leadingEmbedded;
                }
                if ($adjacentTargetArg === $argIndex) {
                    $adjacentExecReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                        $block,
                        $adjacentIndex,
                        $block->orig->children
                    );
                    if (null !== $adjacentExecReturn) {
                        return (string) $adjacentExecReturn;
                    }
                }
            }
        }
        // substr(sprintf('%o', fileperms($path)), -N) — arg #0 is adjacent nested sprintf, not ordinal-0 fileperms (#16451, #16480).
        if ('substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, null) ?? '')) {
            $adjacentNestedSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, $argIndex);
            if (null !== $adjacentNestedSlot) {
                return $adjacentNestedSlot;
            }
        }
        // replaceWith($d->createElement('x'), 'txt', $d->createElement('y')) — when an embedded
        // literal sits between MethodCall producers, map by non-embedded dead-temp ordinal (#21901).
        $hasEmbeddedLiteralArg = false;
        foreach ($cfgCallOp->args as $scanArg) {
            if ($this->isEmbeddedCallLiteralArg($scanArg)) {
                $hasEmbeddedLiteralArg = true;
                break;
            }
        }
        if ($hasEmbeddedLiteralArg) {
            $nonEmbeddedDeadIndices = [];
            foreach ($cfgCallOp->args as $i => $scanArg) {
                if (
                    $scanArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($scanArg)
                    && !$this->isEmbeddedCallLiteralArg($scanArg)
                ) {
                    $nonEmbeddedDeadIndices[] = (int) $i;
                }
            }
            $producerOrdinal = array_search($argIndex, $nonEmbeddedDeadIndices, true);
            if (false === $producerOrdinal) {
                return null;
            }
            $producerOrdinal = (int) $producerOrdinal;
        } else {
            $producerOrdinal = $argIndex - $leadingEmbedded;
        }
        if ($producerOrdinal < 0 || $producerOrdinal >= $siblingFuncCount) {
            return null;
        }
        $producerIndex = $this->siblingInlineFuncCallProducerIndexAtOrdinal(
            $producerOrdinal,
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (null === $producerIndex) {
            return null;
        }
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr || !$this->isSiblingInlineCallProducerExpr($producer)) {
            return null;
        }
        $targetArgForProducer = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        );
        if (null !== $targetArgForProducer && $targetArgForProducer !== $argIndex) {
            return null;
        }
        if (
            $hasEmbeddedLiteralArg
            && ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
        ) {
            $methodSlot = $this->slotForMethodOrStaticCallInitFollowingExecReturnMatchingArgs(
                $block,
                $producer
            );
            if (null !== $methodSlot) {
                return $methodSlot;
            }
        }
        $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
            $block,
            $producerIndex,
            $block->orig->children
        );
        if (null === $execReturn) {
            $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
                $firstSibling,
                $callIndex,
                $block->orig->children
            );
            $execReturnCount = $block->funccallExecReturnCount();
            if ($this->forceDeferredSiblingCallReturnSlot) {
                $execOrdinal = $execReturnCount;
            } else {
                $execOrdinal = $execReturnCount - $chainProducerCount + $producerOrdinal;
            }
            $execReturn = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal($block, $execOrdinal);
        }

        return null !== $execReturn ? (string) $execReturn : null;
    }

}
