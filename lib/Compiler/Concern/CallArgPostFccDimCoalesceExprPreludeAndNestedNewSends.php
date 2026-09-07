<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;

/**
 * Post-FCC Block-cache O(1) dimFetch / synced-coalesce / expression-prelude /
 * adjacent-nested FuncCall / nested-New_ ARG_SEND heuristics (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. `$dimFetchSlot` is resolved in the hub (reused by later inline-array
 * paths); `$sends` is by-ref so compileExpr prelude ops stay on the send list.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgPostFccDimCoalesceExprPreludeAndNestedNewSends
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @return bool true when an ARG_SEND (and any preludes) were appended — caller continues
     */
    private function tryCompileCallArgPostFccDimCoalesceExprPreludeAndNestedNewSend(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array $args,
        mixed $nameSlot,
        mixed $unpackFlag,
        ?string $dimFetchSlot,
        array &$sends
    ): bool {
        $callArgForSync = null !== $cfgCallOp
            ? (($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg)
            : $arg;
        $syncedPreludeArgSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgForSync);
        if (null !== $syncedPreludeArgSlot) {
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                (string) $syncedPreludeArgSlot,
                $nameSlot,
                $unpackFlag
            );
            return true;
        }
        $exprPreludeSlot = null === $dimFetchSlot
            ? $this->resolvePrecedingExpressionPreludeCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            )
            : null;
        if (null !== $exprPreludeSlot) {
            if (null !== $cfgCallOp && null !== $block->orig) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $prelude = $block->orig->children[$callIndex - 1] ?? null;
                    if (
                        $prelude instanceof Op\Expr\PropertyFetch
                        || $prelude instanceof Op\Expr\NullsafePropertyFetch
                    ) {
                        $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall(
                            $block,
                            $prelude
                        );
                        if (null !== $opcodeSlot) {
                            $exprPreludeSlot = (string) $opcodeSlot;
                        }
                    }
                }
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $exprPreludeSlot,
                $nameSlot,
                $unpackFlag
            );
            return true;
        }
        // unserialize(serialize($obj)) — adjacent hoisted serialize must feed arg #0, not stale New_ slot (#16241).
        if (
            null === $dimFetchSlot
            && null !== $cfgCallOp
            && null !== $block->orig
            && !$this->isCallArgDirectArrayDimFetch($arg)
            && $this->callArgIsDeadInlineTemporary($arg)
            && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
            && !(
                ($this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                    || $this->hasSiblingMultiArgInlineNewProducers($block, $cfgCallOp))
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                && null === $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                    $cfgCallOp,
                    (int) ($this->cfgCallOpIndex($block, $cfgCallOp) ?? -1),
                    $block->orig->children
                )
            )
        ) {
            $adjacentNestedProducerSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $adjacentNestedProducerSlot) {
                // Prefer exact argument→producer link over adjacent last-EXEC_RETURN steal
                // (openssl_decrypt(str_repeat('k'), …, str_repeat('i')) + later ?: left both
                // args on the IV slot — #35879 / peer #23354 exactHoisted last word).
                $exactAdjacentSlot = $this->exactHoistedCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                $sends[] = new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    null !== $exactAdjacentSlot ? $exactAdjacentSlot : $adjacentNestedProducerSlot,
                    $nameSlot,
                    $unpackFlag
                );
                return true;
            }
        }
        if (
            null === $dimFetchSlot
            && null !== $cfgCallOp
            && null !== $block->orig
            && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
        ) {
            $nestedNewProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $positionalNewProducers = $nestedNewProducers;
            $nestedNewArgCount = \count($cfgCallOp->args ?? $args);
            $siblingNews = $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp);
            if ([] !== $siblingNews) {
                // new LimitIterator(new ArrayIterator([...]), …) — keep Array_ prelude + inner New_ (#12916, #17575).
                if (
                    null === $this->matchNestedNewCtorInlineNewProducer(
                        $nestedNewProducers,
                        (int) $argIndex,
                        $nestedNewArgCount,
                        $cfgCallOp->args ?? $args
                    )
                ) {
                    $nestedNewProducers = $siblingNews;
                    $positionalNewProducers = $siblingNews;
                }
            }
            $nestedNewProducerCount = \count($nestedNewProducers);
            $inlineNewProducer = $this->matchSiblingInlineNewCallArgProducer(
                $nestedNewProducers,
                $cfgCallOp->args ?? $args,
                (int) $argIndex
            );
            if (
                null === $inlineNewProducer
                && 1 === \count($siblingNews)
                && 1 === $nestedNewArgCount
                && 0 === (int) $argIndex
            ) {
                $singleArgCallArg = ($cfgCallOp->args ?? $args)[0] ?? null;
                if (null !== $singleArgCallArg && $this->callArgIsDeadInlineTemporary($singleArgCallArg)) {
                    $inlineNewProducer = $siblingNews[0];
                }
            }
            if (null === $inlineNewProducer) {
                $inlineNewProducer = $this->matchNestedNewCtorInlineNewProducer(
                    $nestedNewProducers,
                    (int) $argIndex,
                    $nestedNewArgCount,
                    $cfgCallOp->args ?? $args
                );
            }
            if (null === $inlineNewProducer) {
                $inlineNewProducer = $this->matchPositionalInlineNewCallArgProducer(
                    $positionalNewProducers,
                    $cfgCallOp->args ?? $args,
                    (int) $argIndex
                );
            }
            if (null === $inlineNewProducer) {
                $inlineNewProducer = $this->matchTrailingInlineNewCallArgProducer(
                    $nestedNewProducers,
                    $cfgCallOp->args ?? $args,
                    (int) $argIndex
                );
            }
            if ($inlineNewProducer instanceof Op\Expr\New_) {
                $nestedNewCallArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                $nestedNewLocal = null !== Block::resolveVariableName($nestedNewCallArg)
                    ? (
                        $this->namedLocalCallArgSlotIfBound($nestedNewCallArg, $block, $cfgCallOp, (int) $argIndex)
                        ?? $this->slotForNamedLocalFromAssignVarOperand($nestedNewCallArg, $block)
                    )
                    : null;
                if (null === $nestedNewLocal) {
                    $innerNewSlot = $this->slotForInlineNewProducer($block, $inlineNewProducer, $sends);
                    if (null === $innerNewSlot) {
                        foreach ($this->compileExpr($inlineNewProducer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $innerNewSlot = $this->slotForInlineNewProducer($block, $inlineNewProducer, $sends);
                    }
                    if (null !== $innerNewSlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            $innerNewSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
