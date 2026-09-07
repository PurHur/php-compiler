<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Residual early valueSlot resolvers after encapsed/concat/arithmetic/named-local
 * (#36387 / #36403): direct ArrayDimFetch, preceding dim, coalesce, inline New_,
 * hoisted empty, embedded literal, bool/null/compile-time fold, runtime enum
 * const-fetch prefetch, and matched preceding-inline producer (incl. array_merge
 * trailing / hoisted ConstFetch remap).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$prefetchOps` / `$sends` by-ref when
 * `!$tookDimOrInlineArrayBranch`. Mirrors php-src Zend/zend_compile.c call-arg
 * send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgDirectDimCoalesceNewFoldEnumAndMatchedProducerValueSlots
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param list<OpCode> $prefetchOps enum const-fetch ops (merged into sends later)
     * @param list<Op\Expr> $inlineProducerCfgChildren
     * @param-out mixed $valueSlot
     * @param-out list<OpCode> $prefetchOps
     */
    private function resolveCallArgDirectDimCoalesceNewFoldEnumAndMatchedProducerValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $callArgOperand,
        int $callOrdinal,
        array $inlineProducerCfgChildren,
        array &$sends,
        &$valueSlot,
        array &$prefetchOps
    ): void {
        if (null === $valueSlot && $this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->compileOperand($arg, $block, true);
        } elseif (null === $valueSlot) {
            $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
        }
        if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
        }
        if (
            null === $valueSlot
            && null !== $cfgCallOp
            && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
        ) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $inlineProducerCfgChildren,
                $cfgCallOp
            );
            $newProducer = $this->matchInlineCallArgProducer(
                $producers,
                $cfgCallOp->args ?? [],
                (int) $argIndex,
                $cfgCallOp,
                $block,
                $calleeName
            );
            if (
                $newProducer instanceof Op\Expr\New_
                && $this->inlineNewProducerFeedsCallArg(
                    $newProducer,
                    $cfgCallOp->args[(int) $argIndex] ?? $arg
                )
            ) {
                if (null === $block->slotForOperand($newProducer->result)) {
                    foreach ($this->compileExpr($newProducer, $block) as $op) {
                        $sends[] = $op;
                    }
                }
                $valueSlot = $this->slotForInlineNewProducer($block, $newProducer, $sends);
                if (null !== $valueSlot) {
                    $block->markDeferredArrayLiteralKeepSlot((int) $valueSlot);
                }
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
        }
        if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->compileHoistedEmptyCallArg($arg, $block);
        }
        if (null === $valueSlot) {
            if ($this->isEmbeddedCallLiteralArg($arg)) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
        }
        if (null === $valueSlot) {
            if (
                null === $calleeName
                || !$this->callArgRequiresByRef($calleeName, (int) $argIndex, $arg, $block)
            ) {
                $valueSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null === $valueSlot) {
                    $valueSlot = $this->tryFoldCallArgCompileTimeValue($arg, $block, $calleeName, $cfgCallOp);
                }
                if (
                    null === $valueSlot
                    && null !== $cfgCallOp
                    && is_array($cfgCallOp->args ?? null)
                    && isset($cfgCallOp->args[(int) $argIndex])
                    && $cfgCallOp->args[(int) $argIndex] !== $arg
                ) {
                    $valueSlot = $this->tryFoldCallArgCompileTimeValue(
                        $cfgCallOp->args[(int) $argIndex],
                        $block,
                        $calleeName,
                        $cfgCallOp
                    );
                }
            }
        }
        if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
        }
        if (null === $valueSlot) {
            $prefetchOps = $this->compileCallArgRuntimeEnumConstFetchOps(
                $arg,
                $block,
                (int) $argIndex,
                $callOrdinal,
                $cfgCallOp
            );
            if ([] !== $prefetchOps && !$this->callArgOperandIsClosureValue($arg, $block)) {
                $skipEnumPrefetchForPropertyProducer = false;
                if (null !== $cfgCallOp && null !== $block->orig) {
                    $prefetchProducers = $this->filterDeadClassConstFetchInlineProducers(
                        $this->precedingInlineCallArgProducersBeforeCfgOp(
                            $block->orig->children,
                            $cfgCallOp
                        )
                    );
                    foreach ($prefetchProducers as $prefetchProducer) {
                        if ($prefetchProducer instanceof Op\Expr\PropertyFetch
                            || $prefetchProducer instanceof Op\Expr\NullsafePropertyFetch
                            || $prefetchProducer instanceof Op\Expr\NullsafeMethodCall) {
                            $skipEnumPrefetchForPropertyProducer = true;
                            break;
                        }
                    }
                }
                if (!$skipEnumPrefetchForPropertyProducer) {
                    $valueSlot = $prefetchOps[0]->arg1;
                }
            }
        }
        if (
            null === $valueSlot
            && null !== $cfgCallOp
            && null !== $block->orig
            && !(
                $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                && !$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, (int) $argIndex)
            )
        ) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            if ([] !== $producers) {
                $matched = $this->matchInlineCallArgProducer(
                    $producers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
                if ($matched instanceof Op\Expr) {
                    $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    $matched = $this->preferEmbeddedArrayLiteralOverSiblingFuncCallMatch(
                        $matched,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block,
                        $callArgProbe
                    );
                    $matched = $this->preferSiblingCallOverNestedArrayInlineMatch(
                        $matched,
                        $producers,
                        $callArgProbe
                    );
                    if (
                        ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall)
                        && (int) $argIndex > 0
                        && null !== $calleeName
                        && \in_array(strtolower($calleeName), ['array_merge', 'array_merge_recursive'], true)
                        && $this->isEmbeddedCallLiteralArg($callArgProbe)
                    ) {
                        $mergeTrailingArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        $funcCallFeedsTrailingArg = null !== $mergeTrailingArg
                            && (
                                $matched->result === $mergeTrailingArg
                                || $this->operandsReferToSameVariable($matched->result, $mergeTrailingArg)
                            );
                        if (!$funcCallFeedsTrailingArg) {
                            $matched = null;
                            foreach ($producers as $producer) {
                                if ($producer instanceof Op\Expr\Array_) {
                                    $matched = $producer;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (
                    ($matched instanceof Op\Expr\ConstFetch || $matched instanceof Op\Expr\ClassConstFetch)
                    && null !== $cfgCallOp
                    && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall(
                        $matched,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                ) {
                    $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $adjacentSlot) {
                        $valueSlot = $adjacentSlot;
                        $matched = null;
                    }
                }
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $matchedSlot = $matched instanceof Op\Expr\New_
                        ? ($this->slotForInlineNewProducer($block, $matched)
                            ?? $this->slotForInlineCallArgProducerResult(
                                $block,
                                $matched,
                                $cfgCallOp,
                                $block->orig->children
                            ))
                        : $this->slotForInlineCallArgProducerResult(
                            $block,
                            $matched,
                            $cfgCallOp,
                            $block->orig->children
                        );
                    if (null !== $matchedSlot) {
                        $valueSlot = $matchedSlot;
                    }
                }
            }
        }
    }
}
