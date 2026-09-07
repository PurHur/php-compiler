<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;

/**
 * Residual early valueSlot resolvers after dimFetch / inline Array_|FuncCall wire
 * (#36387 / #36403): encapsed/concat/arithmetic/unary/assign-RHS/bitmask, hoisted
 * isset/empty, named-local assign, first-class callable, direct dim+property fetch,
 * and preceding-inline producer match.
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$assignedNamedLocal` / `$inlineArrayLiteralArgWired`
 * / `$sends` by-ref when `!$tookDimOrInlineArrayBranch`. Mirrors php-src
 * Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgEncapsedConcatArithmeticNamedLocalAndPrecedingProducerValueSlots
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $assignedNamedLocal
     * @param-out bool $inlineArrayLiteralArgWired
     */
    private function resolveCallArgEncapsedConcatArithmeticNamedLocalAndPrecedingProducerValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array &$sends,
        &$valueSlot,
        &$assignedNamedLocal,
        &$inlineArrayLiteralArgWired
    ): void {
        if (null === $valueSlot) {
            $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
        }
        if (null === $valueSlot) {
            $valueSlot = $this->tryResolveEncapsedConcatListCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
        }
        if (
            null === $valueSlot
            && !(
                null !== $cfgCallOp
                && $this->callArgIsDeadInlineHaystackFamilySlot(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $arg
                )
            )
        ) {
            $valueSlot = $this->tryResolveChainedConcatCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
        }
        if (null === $valueSlot) {
            $valueSlot = $this->tryResolveChainedArithmeticCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
        }
        if (null === $valueSlot) {
            $valueSlot = $this->tryResolveUnaryLiteralCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
        }
        if (null === $valueSlot && null !== $cfgCallOp) {
            $assignRhsSlot = $this->resolveAdjacentAssignExprCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $assignRhsSlot) {
                $valueSlot = $assignRhsSlot;
            }
        }
        if (null === $valueSlot) {
            $valueSlot = $this->tryResolveInlineBitmaskCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
        }
        if (null === $valueSlot && null !== $cfgCallOp && !$this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
        }
        $assignVarProbe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $assignVarProbe = $cfgCallOp->args[(int) $argIndex];
        }
        $assignedNamedLocal = $this->slotForNamedLocalFromAssignVarOperand($assignVarProbe, $block);
        if (null === $valueSlot && null !== $assignedNamedLocal) {
            $namedAssignDest = $block->slotForNamedAssignDest($assignVarProbe);
            $valueSlot = null !== $namedAssignDest
                ? $this->resolveNamedAssignCallArgSlot(
                    $block,
                    (int) $namedAssignDest,
                    $calleeName,
                    (int) $argIndex,
                    $assignVarProbe
                )
                : (string) $this->finalizeOperandSlotForAccess(
                    $block,
                    $assignedNamedLocal,
                    true
                );
        }
        if (null === $valueSlot) {
            $valueSlot = $this->resolveInlineFirstClassCallableCallArgSlot($arg, $block, $cfgCallOp, (int) $argIndex);
        }
        if (
            null === $valueSlot
            && (
                $this->isCallArgDirectArrayDimFetch($arg)
                || (
                    null !== $cfgCallOp
                    && $this->callArgIsDeadInlineHaystackFamilySlot(
                        $cfgCallOp,
                        (int) $argIndex,
                        $calleeName,
                        $arg
                    )
                )
            )
        ) {
            $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
        }
        if (null === $valueSlot && $this->isCallArgDirectArrayDimFetch($arg)) {
            $fetch = $this->unwrapOperandChain($arg);
            if ($fetch instanceof Op\Expr\ArrayDimFetch && null !== $fetch->result) {
                if (null === $block->slotForOperand($fetch->result)) {
                    foreach ($this->compileExpr($fetch, $block) as $op) {
                        $sends[] = $op;
                    }
                }
                $fetchSlot = $block->slotForOperand($fetch->result);
                if (null !== $fetchSlot) {
                    $valueSlot = $fetchSlot;
                }
            }
        }
        if (null === $valueSlot && $this->isCallArgDirectPropertyFetch($arg)) {
            $fetch = $this->unwrapOperandChain($arg);
            if ($fetch instanceof Op\Expr\PropertyFetch && null !== $fetch->result) {
                if (null === $block->slotForOperand($fetch->result)) {
                    foreach (
                        $this->compileCallArgPropertyFetch(
                            $fetch,
                            $block,
                            $calleeName,
                            (int) $argIndex
                        ) as $op
                    ) {
                        $sends[] = $op;
                    }
                }
                $fetchSlot = $block->slotForOperand($fetch->result);
                if (null !== $fetchSlot) {
                    $valueSlot = $fetchSlot;
                }
            }
        }
        if (null === $valueSlot && null !== $cfgCallOp && null !== $block->orig) {
            if (
                !(
                    $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
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
                        // array_merge(array_keys(...), ['b']) — trailing Array_, not leading FuncCall (#13704).
                        // array_merge(['a'=>1], array_keys(...)) — keep FuncCall when it feeds arg #1 (#13775).
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
                    $callArgForMatch = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    if (
                        $this->isComparisonInlineCallArgProducer($matched)
                        && null !== $callArgForMatch
                        && !$this->operandsReferToSameVariable($matched->result, $callArgForMatch)
                    ) {
                        $matched = null;
                        $constRoot = $this->unwrapOperandChain($callArgForMatch);
                        if ($constRoot instanceof Op\Expr\ConstFetch) {
                            $folded = $this->tryFoldGlobalConstFetch($constRoot);
                            if (null !== $folded) {
                                $valueSlot = (string) $block->registerConstant($callArgForMatch, $folded);
                            }
                        }
                    }
                    if ($matched instanceof Op\Expr) {
                        if ($matched instanceof Op\Expr\Array_) {
                            $arrayOps = $this->compileArrayLiteral($matched, $block);
                            if ([] !== $arrayOps) {
                                $sends = array_merge($sends, $arrayOps);
                            }
                            $initSlot = $this->slotFromInitArrayLiteralOps($arrayOps);
                            $matchedSlot = $initSlot ?? $block->slotForOperand($matched->result);
                            if (null !== $initSlot) {
                                $inlineArrayLiteralArgWired = true;
                            }
                        } elseif (null === $block->slotForOperand($matched->result)) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $sends[] = $op;
                            }
                            $matchedSlot = $matched instanceof Op\Expr\New_
                                ? $this->slotForInlineNewProducer($block, $matched, $sends)
                                : $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    null !== $block->orig ? $block->orig->children : null
                                );
                        } else {
                            $matchedSlot = $matched instanceof Op\Expr\New_
                                ? ($this->slotForInlineNewProducer($block, $matched, $sends)
                                    ?? $this->slotForInlineCallArgProducerResult(
                                        $block,
                                        $matched,
                                        $cfgCallOp,
                                        null !== $block->orig ? $block->orig->children : null
                                    ))
                                : $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    null !== $block->orig ? $block->orig->children : null
                                );
                        }
                        if (null !== $matchedSlot) {
                            $valueSlot = $matchedSlot;
                        }
                    }
                }
            }
            }
        }
    }
}
