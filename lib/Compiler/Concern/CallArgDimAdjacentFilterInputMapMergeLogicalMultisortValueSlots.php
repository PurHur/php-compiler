<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-filter/json/merge early valueSlot wiring (#36387 / #36403): preceding
 * ArrayDimFetch / adjacent nested FuncCall rematch, filter_input hoisted
 * ConstFetch/Array_, array_map leading FCC/closure callback, array_merge family
 * full-inline producers, logical short-circuit andPhi copy, and array_multisort
 * literal Array_ rematch.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgFilterJsonDecodeMergeMapFilterSplitExplodeValueSlots} so gen-0
 * split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends` /
 * `$inlineArrayLiteralArgWired` by-ref.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgDimAdjacentFilterInputMapMergeLogicalMultisortValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgDimAdjacentFilterInputMapMergeLogicalMultisortValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        bool $hoistedEnumPropertyCallArgSlotWired,
        array &$sends,
        &$valueSlot,
        bool &$inlineArrayLiteralArgWired
    ): void {
        $dimFetchArgSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
            $arg,
            $block,
            $cfgCallOp,
            (int) $argIndex
        );
        if (null !== $dimFetchArgSlot) {
            $valueSlot = $dimFetchArgSlot;
        } elseif (
            null !== $cfgCallOp
            && $this->callArgIsDeadInlineTemporary($arg)
            && !$this->callArgIsNullLiteral(
                $cfgCallOp->args[(int) $argIndex] ?? $arg,
                $cfgCallOp,
                (int) $argIndex,
                $block
            )
            && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
        ) {
            $adjacentArgSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null === $adjacentArgSlot) {
                $adjacentArgSlot = $this->resolveImmediatePrecedingCallProducerArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $arg
                );
            }
            if (null !== $adjacentArgSlot) {
                if (
                    0 === (int) $argIndex
                    && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                ) {
                    // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not nested keys (#13760, #16418).
                } elseif (!$hoistedEnumPropertyCallArgSlotWired) {
                    // Keep ClassConstFetch/flag wiring (CachingIterator::FULL_CACHE — #19769).
                    $valueSlot = $adjacentArgSlot;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
        ) {
            $hoisted = [];
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $child = $block->orig->children[$i];
                    if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\Array_) {
                        array_unshift($hoisted, $child);
                        continue;
                    }
                    if ($child instanceof Op\Expr\Assign) {
                        break;
                    }
                    break;
                }
            }
            $constFetches = array_values(array_filter(
                $hoisted,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
            ));
            $arrayProducers = array_values(array_filter(
                $hoisted,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            $target = match ((int) $argIndex) {
                0 => $constFetches[0] ?? null,
                2 => $constFetches[1] ?? null,
                3 => $arrayProducers[\count($arrayProducers) - 1] ?? null,
                default => null,
            };
            if ($target instanceof Op\Expr\ConstFetch) {
                $folded = $this->tryFoldGlobalConstFetch($target);
                if (null !== $folded) {
                    $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
                } else {
                    $slot = $block->slotForOperand($target->result);
                    if (null === $slot) {
                        foreach ($this->compileExpr($target, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($target->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                }
            } elseif ($target instanceof Op\Expr\Array_) {
                $slot = $block->slotForOperand($target->result);
                if (null === $slot) {
                    foreach ($this->compileArrayLiteral($target, $block) as $op) {
                        $sends[] = $op;
                    }
                    $slot = $block->slotForOperand($target->result);
                }
                if (null !== $slot) {
                    $valueSlot = (string) $slot;
                }
            }
        }
        if (
            'array_map' === strtolower($calleeName ?? '')
            && 0 === (int) $argIndex
            && null !== $cfgCallOp
            && null !== $block->orig
        ) {
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                $fccSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                if (null !== $fccSlot) {
                    $valueSlot = (string) $fccSlot;
                }
            } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                || $leadingCallback instanceof Op\Expr\Closure) {
                $closureSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                if (null !== $closureSlot) {
                    $valueSlot = (string) $closureSlot;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && \in_array(
                strtolower($calleeName ?? ''),
                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                true
            )
        ) {
            $mergeCallArg = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                $mergeCallArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($mergeCallArg)
                && $this->callArgOperandExpectsArrayProducer($mergeCallArg)
            ) {
                $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                    $block->orig->children,
                    $cfgCallOp
                );
                $mergeArgCount = \count($cfgCallOp->args ?? []);
                if ($mergeArgCount >= 2 && \count($mergeProducers) >= 2) {
                    if (
                        0 === (int) $argIndex
                        && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                    ) {
                        $leadingInitSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                        if (null !== $leadingInitSlot) {
                            $valueSlot = $leadingInitSlot;
                            $inlineArrayLiteralArgWired = true;
                        }
                    }
                    if (null === $valueSlot) {
                    $mergeMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                        $mergeProducers,
                        (int) $argIndex,
                        $mergeArgCount,
                        is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                    );
                    if ($mergeMapped instanceof Op\Expr) {
                        $mergeSlot = $block->slotForOperand($mergeMapped->result);
                        if (
                            null === $mergeSlot
                            && $mergeMapped instanceof Op\Expr\Array_
                            && 0 === (int) $argIndex
                        ) {
                            $mergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                        }
                        if (null === $mergeSlot) {
                            foreach ($this->compileExpr($mergeMapped, $block) as $op) {
                                $sends[] = $op;
                            }
                            $mergeSlot = $block->slotForOperand($mergeMapped->result);
                            if (
                                null === $mergeSlot
                                && $mergeMapped instanceof Op\Expr\Array_
                                && 0 === (int) $argIndex
                            ) {
                                $mergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                            }
                        }
                        if (null !== $mergeSlot) {
                            $valueSlot = (string) $mergeSlot;
                            if ($mergeMapped instanceof Op\Expr\Array_) {
                                $inlineArrayLiteralArgWired = true;
                            }
                        }
                    }
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && !$this->callArgOperandExpectsArrayProducer($arg)
            && !$inlineArrayLiteralArgWired
        ) {
            $andPhi = $this->logicalShortCircuitPhiMergeSlot($block);
            if (
                null !== $andPhi
                && null !== $valueSlot
                && $this->isNamedVariableOperand($arg)
            ) {
                $namedSlot = $block->slotForNamedAssignDest($arg) ?? (int) $valueSlot;
                $copyOperand = new Operand\Temporary();
                $copySlot = $block->forceFreshVarSlot($copyOperand, (int) $andPhi);
                $sends[] = new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $copySlot,
                    $copySlot,
                    $namedSlot,
                );
                $valueSlot = (string) $copySlot;
            } elseif (
                null !== $andPhi
                && null !== $valueSlot
                && (string) $valueSlot === (string) $andPhi
                && $this->callArgIsDeadInlineTemporary($arg)
            ) {
                $pendingNested = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($sends)
                    ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
                $calleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                if (
                    null !== $pendingNested
                    && !\in_array($calleeLower, ['exit', 'die'], true)
                    && !(
                        'array_map' === $calleeLower
                        && 0 === (int) $argIndex
                    )
                ) {
                    $valueSlot = (string) $pendingNested;
                }
            }
        }
        if (
            'array_multisort' === strtolower($calleeName ?? '')
            && null !== $cfgCallOp
            && null !== $block->orig
        ) {
            $multisortArgProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                $this->callArgIsDeadInlineTemporary($multisortArgProbe)
                && !$this->isCallArgDirectArrayDimFetch($multisortArgProbe)
                && null === $this->resolvePrecedingArrayDimFetchCallArgSlot(
                    $multisortArgProbe,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                )
            ) {
                // Inline literal — assign-in-call may sit between the second Array_ and FuncCall (#15151).
                $stmtBefore = $this->inlineArrayMultisortLiteralProducerForArg(
                    $cfgCallOp,
                    $block,
                    (int) $argIndex
                ) ?? $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                if ($stmtBefore instanceof Op\Expr\Array_) {
                    if (null === $block->slotForOperand($stmtBefore->result)) {
                        foreach ($this->compileArrayLiteral($stmtBefore, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $leadArraySlot = $block->slotForOperand($stmtBefore->result);
                    if (null !== $leadArraySlot) {
                        $valueSlot = (string) $leadArraySlot;
                    }
                }
            }
        }
    }
}
