<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Sibling multi-arg SEND rewire + hoisted ConstFetch prelude guards (#36387 / #36403).
 *
 * Extracted from {@see RewireArithmeticBranchSubstrEnumAndSiblingMultiArgCallArgSendSlots}
 * so gen-0 split-TU can hollow a smaller Concern TU. Parent keeps arithmetic-branch /
 * substr+sprintf / enum-prefix / nested MethodCall ClassConst peers.
 *
 * Covers {@see rewireSiblingMultiArgInlineCallArgSendSlots},
 * {@see hoistedDeadInlinePreludeProducerForCallArgIndex},
 * {@see callArgHasHoistedConstPrelude}, and
 * {@see opcodeSlotIsHoistedConstPrelude}.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait RewireSiblingMultiArgInlineCallArgSendSlots
{
    /**
     * var_dump(f(), g()) after an earlier sibling chain — map ARG_SEND to chain EXEC_RETURN slots (#16254).
     *
     * @param list<OpCode> $outerArgSends
     */
    private function rewireSiblingMultiArgInlineCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex)) {
            return;
        }
        $cfgChildren = $block->orig->children;
        if ($this->isSubstrNestedSprintfUnaryMinusPattern($cfgCallOp, $callIndex, $cfgChildren)) {
            return;
        }
        $firstSibling = $this->firstContiguousSiblingMultiArgProducerIndex(
            $callIndex,
            $cfgCallOp,
            $cfgChildren
        );
        if (!\is_int($firstSibling)) {
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndexImpl(
                $callIndex,
                $cfgChildren
            );
        }
        if (!\is_int($firstSibling) || ($callIndex - $firstSibling) < 2) {
            return;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — do not rewire ARG_SEND onto stmt
        // StaticCall EXEC_RETURN when StaticPropertyFetch covers the dead-temp args (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $cfgChildren,
            $cfgCallOp
        )) {
            return;
        }
        $chainProducerCount = $this->countContiguousSiblingMultiArgProducers(
            $firstSibling,
            $callIndex,
            $cfgCallOp,
            $cfgChildren
        );
        if ($chainProducerCount < 2) {
            if (!$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, 0)) {
                return;
            }
            $this->rewireNestedFuncCallEnumPrefixCallArgSendSlots(
                $outerArgSends,
                $block,
                $cfgCallOp,
                $pendingNestedProducerOps
            );

            return;
        }
        $execReturnCount = $block->funccallExecReturnCount();
        foreach ($pendingNestedProducerOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                ++$execReturnCount;
            }
        }
        $argIndex = 0;
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
            $this->resolveCfgFuncCallName($cfgCallOp)
        );
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (
                $callbackArgIndex >= 0
                && $argIndex === $callbackArgIndex
                && null !== $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block)
            ) {
                // array_map(intval(...), str_split(str_repeat(...))) — keep FCC slot, not haystack EXEC_RETURN (#16279).
                ++$argIndex;
                continue;
            }
            if (
                0 === $argIndex
                && 1 === $callbackArgIndex
                && 2 === \count($cfgCallOp->args ?? [])
            ) {
                $trailingHaystack = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
                if (
                    $trailingHaystack instanceof Op\Expr\FuncCall
                    || $trailingHaystack instanceof Op\Expr\NsFuncCall
                ) {
                    // array_filter(str_split(...), …) after a prior filter+var_dump — bind haystack
                    // EXEC_RETURN explicitly; sibling ordinal steals var_dump (#27344 / #15490).
                    $haystackSlot = $block->slotForOperand($trailingHaystack->result);
                    if (null === $haystackSlot) {
                        $haystackIndex = array_search($trailingHaystack, $cfgChildren, true);
                        $haystackSlot = \is_int($haystackIndex)
                            ? $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                                $block,
                                $haystackIndex,
                                $cfgChildren
                            )
                            : null;
                    }
                    if (null !== $haystackSlot) {
                        $send->arg1 = (string) $haystackSlot;
                    }
                    ++$argIndex;
                    continue;
                }
            }
            $hoistedPrelude = $this->hoistedPreludeProducerForCallArgIndex($cfgCallOp, $argIndex, $block);
            if (
                $hoistedPrelude instanceof Op\Expr\ConstFetch
                || $hoistedPrelude instanceof Op\Expr\ClassConstFetch
            ) {
                // setlocale(LC_ALL, null) after earlier setlocale stmts — keep hoisted LC_* / null prelude (#10177).
                ++$argIndex;
                continue;
            }
            if ($this->callArgHasHoistedConstPrelude($cfgCallOp, $argIndex, $block)) {
                ++$argIndex;
                continue;
            }
            if ($this->opcodeSlotIsHoistedConstPrelude($block, $send->arg1, $pendingNestedProducerOps)) {
                ++$argIndex;
                continue;
            }
            $multiArgCallArg = $cfgCallOp->args[$argIndex] ?? null;
            if (!$this->callArgIsDeadInlineTemporary($multiArgCallArg)) {
                ++$argIndex;
                continue;
            }
            if (
                'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $multiArgCallArg instanceof Operand
                && $this->isCallArgDirectArrayDimFetch($multiArgCallArg)
            ) {
                ++$argIndex;
                continue;
            }
            $preludeSlot = $this->slotForImmediateConstFetchPreludeCallArg($block, $cfgCallOp, $argIndex);
            if (null !== $preludeSlot) {
                $send->arg1 = (string) $preludeSlot;
                ++$argIndex;
                continue;
            }
            $producerIndex = null;
            for ($j = $firstSibling; $j < $callIndex; ++$j) {
                $scan = $cfgChildren[$j] ?? null;
                if (!$scan instanceof Op\Expr) {
                    continue;
                }
                $feedsArg = $this->isSiblingMultiArgFuncCallProducer(
                    $scan,
                    $cfgCallOp,
                    $j,
                    $callIndex,
                    $cfgChildren
                ) || $this->isNestedCallArgProducerForConsumer(
                    $scan,
                    $cfgCallOp,
                    $j,
                    $callIndex,
                    $cfgChildren
                );
                if (!$feedsArg) {
                    continue;
                }
                if (
                    $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                        $j,
                        $callIndex,
                        $cfgChildren
                    ) === $argIndex
                ) {
                    $producerIndex = $j;
                    break;
                }
            }
            if (!\is_int($producerIndex)) {
                ++$argIndex;
                continue;
            }
            $consumerName = $this->resolveCfgFuncCallName($cfgCallOp);
            $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($consumerName);
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if (
                $callbackArgIndex >= 0
                && 2 === \count($cfgCallOp->args ?? [])
                && $argIndex === $callbackArgIndex
                && ($leadingCallback instanceof Op\Expr\ArrowFunction
                    || $leadingCallback instanceof Op\Expr\Closure
                    || $leadingCallback instanceof Op\Expr\FirstClassCallable)
            ) {
                // array_map(intval(...), str_split(...)) — keep FCC/closure send slot (#15487, #16279).
                ++$argIndex;
                continue;
            }
            $siblingOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $producerIndex,
                $firstSibling,
                $block->orig->children
            );
            $execOrdinal = $execReturnCount - $chainProducerCount + $siblingOrdinal;
            $slot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
                $block,
                $execOrdinal,
                $pendingNestedProducerOps
            );
            if (null !== $slot) {
                $send->arg1 = (string) $slot;
            }
            ++$argIndex;
        }
    }

    /**
     * Map dead inline call-arg temps to hoisted UnaryMinus/ConstFetch preludes before the callee (#16523).
     */
    private function hoistedDeadInlinePreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        if (!property_exists($callOp, 'args') || !\is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if ([] === $preludes) {
            return null;
        }
        $deadInlineArgCount = 0;
        foreach ($callOp->args as $deadArg) {
            if ($this->isEmbeddedCallLiteralArg($deadArg)) {
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                ++$deadInlineArgCount;
            }
        }
        // in_array('x', g(), true) — hoisted FuncCall between trailing ConstFetch and consumer (#16540).
        if ($deadInlineArgCount !== \count($preludes)) {
            return null;
        }
        $preludeOrdinal = 0;
        foreach ($callOp->args as $i => $deadArg) {
            if ($this->isEmbeddedCallLiteralArg($deadArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($deadArg)) {
                continue;
            }
            if ($deadArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($deadArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;
                if (
                    ($prelude instanceof Op\Expr\ConstFetch || $prelude instanceof Op\Expr\ClassConstFetch)
                    && $callArg instanceof Operand
                    && $this->callArgOperandExpectsArrayProducer($callArg)
                    && null !== $block->orig
                ) {
                    // in_array('x', get_declared_classes(), true) — strict ConstFetch is not haystack (#16540, re-#16312).
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                    $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                    if (
                        $matched instanceof Op\Expr\FuncCall
                        || $matched instanceof Op\Expr\NsFuncCall
                        || $matched instanceof Op\Expr\StaticCall
                        || $matched instanceof Op\Expr\MethodCall
                    ) {
                        return $matched;
                    }

                    return null;
                }

                return $prelude instanceof Op\Expr ? $prelude : null;
            }
            ++$preludeOrdinal;
        }

        return null;
    }

    /**
     * Hoisted ConstFetch/ClassConstFetch immediately before a call feeds a dead inline arg (#10177).
     */
    private function callArgHasHoistedConstPrelude(Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if ('setlocale' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block);
        if ([] === $preludes) {
            $preludes = $this->hoistedPreludeProducersBeforeAssignStmt($cfgCallOp, $block);
        }
        if ([] === $preludes) {
            return false;
        }
        $preludeOrdinal = 0;
        foreach ($cfgCallOp->args as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;

                return $prelude instanceof Op\Expr\ConstFetch
                    || $prelude instanceof Op\Expr\ClassConstFetch;
            }
            ++$preludeOrdinal;
        }

        return false;
    }

    /**
     * ARG_SEND already wired to hoisted ConstFetch — do not replace with sibling EXEC_RETURN (#10177).
     *
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function opcodeSlotIsHoistedConstPrelude(
        Block $block,
        int|string|null $slot,
        array $pendingNestedProducerOps = []
    ): bool {
        if (null === $slot) {
            return false;
        }
        $slot = (string) $slot;
        foreach (array_merge($block->opCodes, $pendingNestedProducerOps) as $op) {
            if (
                (OpCode::TYPE_CONST_FETCH === $op->type || OpCode::TYPE_CLASS_CONST_FETCH === $op->type)
                && (string) $op->arg1 === $slot
            ) {
                return true;
            }
        }

        return false;
    }

}
