<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;

/**
 * Post-dim-adjacent early valueSlot wiring (#36387 / #36403): assign-in-call /
 * by-ref RHS rematch, proc_open inline slot, nested ternary merge, haystack-family
 * sibling/outer producers, ConstFetch+FuncCall prelude split, array_slice inline,
 * chained arithmetic/concat + array_merge final / dimFetch / trailing ConstFetch /
 * hoisted producer rematch, and IIFE hoisted producer slot.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgDimAdjacentFilterInputMapMergeLogicalMultisortValueSlots} so gen-0
 * split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends` /
 * `$outerMultiArraySetOpArgWired` by-ref.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $callArgOperand,
        mixed $inlineArray,
        bool $inlineArrayLiteralArgWired,
        bool $hoistedEnumPropertyCallArgSlotWired,
        array &$sends,
        &$valueSlot,
        bool &$outerMultiArraySetOpArgWired
    ): void {
        if (null !== $cfgCallOp) {
            $assignInCallArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (
                $this->callArgIsAssignInCallOperand($assignInCallArg)
                || (
                    null !== $calleeName
                    && $this->callArgRequiresByRef($calleeName, (int) $argIndex, $arg, $block)
                )
            ) {
                $assignInCallRhs = $this->resolveAssignInCallRhsCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $arg
                );
                if (null !== $assignInCallRhs) {
                    $valueSlot = $assignInCallRhs;
                }
            }
        }
        $procOpenSlot = $this->resolveProcOpenInlineCallArgSlot($block, $cfgCallOp, (int) $argIndex, $sends);
        if (null !== $procOpenSlot) {
            $valueSlot = $procOpenSlot;
        }
        $ternaryMergeSlot = $this->resolveNestedTernaryMergeCallArgSlot(
            $block,
            $cfgCallOp,
            (int) $argIndex,
            $callArgOperand ?? $arg
        );
        if (
            null !== $ternaryMergeSlot
            && !(
                $this->callArgIsDeadInlineTemporary($arg)
                && $this->callArgOperandExpectsArrayProducer($arg)
            )
        ) {
            $valueSlot = $ternaryMergeSlot;
        }
        if (
            null !== $cfgCallOp
            && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
            && !$inlineArrayLiteralArgWired
        ) {
            $arrayProducerSlot = null;
            $siblingEmit = [];
            $siblingFuncCount = 0;
            if (null !== $block->orig) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (null !== $callIndex) {
                    $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex(
                        $callIndex,
                        $block->orig->children
                    );
                    if (null !== $firstSibling) {
                        $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
                            $firstSibling,
                            $callIndex,
                            $block->orig->children
                        );
                    }
                }
            }
            if ($siblingFuncCount >= 2) {
                $useOuterMultiArraySetOpWiring = \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_intersect', 'array_diff', 'array_intersect_key', 'array_diff_key'],
                    true
                );
                if ($useOuterMultiArraySetOpWiring) {
                    // array_intersect(f(g()), f(g())) — outer EXEC_RETURN per arg (#15488, #16280).
                    $arrayProducerSlot = $this->outerSiblingInlineCallArgProducerExecReturnSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $siblingEmit
                    );
                }
                if (null === $arrayProducerSlot) {
                    // array_intersect_assoc(array_keys(), array_keys()) — ordinal sibling wiring (#13778, #15570).
                    $arrayProducerSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $siblingEmit
                    );
                }
                if (null === $arrayProducerSlot) {
                    $arrayProducerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                }
            } else {
                // in_array('x', g(), true) — lone nested producer after stmt calls (#15612, #15829).
                $arrayProducerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                if (null === $arrayProducerSlot) {
                    $arrayProducerSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $siblingEmit
                    );
                }
            }
            if ([] !== $siblingEmit) {
                $sends = array_merge($sends, $siblingEmit);
            }
            if (null !== $arrayProducerSlot) {
                $substrReplaceMapped = null !== $inlineArray
                    && 'substr_replace' === $this->resolveCfgFuncCallName($cfgCallOp);
                if (!$substrReplaceMapped) {
                    $valueSlot = (string) $arrayProducerSlot;
                    $outerMultiArraySetOpArgWired = true;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && \is_array($cfgCallOp->args ?? null)
            && 2 === \count($cfgCallOp->args)
            && 'array_slice' !== $this->resolveCfgFuncCallName($cfgCallOp)
            && 'array_combine' !== $this->resolveCfgFuncCallName($cfgCallOp)
            && !(
                isset($cfgCallOp->args[0])
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0])
            )
            && !$this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)
        ) {
            $constFuncPrelude = $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block)
                ?? $this->splitLeadingConstFetchWithFuncCallCallArg(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                );
            if (null !== $constFuncPrelude) {
                [$constFetch, $funcProducer] = $constFuncPrelude;
                $target = match ((int) $argIndex) {
                    0 => $constFetch,
                    1 => $funcProducer,
                    default => null,
                };
                if ($target instanceof Op\Expr) {
                    if (null === $block->slotForOperand($target->result)) {
                        foreach ($this->compileExpr($target, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $splitSlot = $block->slotForOperand($target->result);
                    if (null !== $splitSlot) {
                        $forcePathExplodePrelude = 'explode' === $this->resolveCfgFuncCallName($cfgCallOp)
                            && $constFetch instanceof Op\Expr\ConstFetch
                            && 'PATH_SEPARATOR' === strtoupper($this->staticNameFromOperand($constFetch->name) ?? '')
                            && (
                                $funcProducer instanceof Op\Expr\FuncCall
                                || $funcProducer instanceof Op\Expr\NsFuncCall
                            )
                            && 'get_include_path' === strtolower($this->resolveCfgFuncCallName($funcProducer) ?? '');
                        if (null === $valueSlot || $forcePathExplodePrelude) {
                            $valueSlot = (string) $splitSlot;
                        }
                    }
                }
            }
        }
        $arraySliceEmit = [];
        $arraySliceSlot = $this->resolveArraySliceInlineCallArgSlot(
            $block,
            $cfgCallOp,
            (int) $argIndex,
            $arg,
            $arraySliceEmit
        );
        if ([] !== $arraySliceEmit) {
            $sends = array_merge($sends, $arraySliceEmit);
        }
        if (null !== $arraySliceSlot) {
            $valueSlot = $arraySliceSlot;
        } elseif (null !== $cfgCallOp && null !== $block->orig && !$outerMultiArraySetOpArgWired) {
            // Dead hoisted call-arg temps must wire to preceding inline producers, not echo/ternary phi slots (#14419).
            $finalArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if ($this->callArgIsDeadInlineTemporary($finalArgProbe)) {
                $finalChainOps = [];
                $chainedArithmeticSlot = $this->tryResolveChainedArithmeticCallArgSlot(
                    $finalArgProbe,
                    $block,
                    $finalChainOps,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $chainedArithmeticSlot) {
                    if ([] !== $finalChainOps) {
                        $sends = array_merge($sends, $finalChainOps);
                    }
                    $valueSlot = (string) $chainedArithmeticSlot;
                } else {
                    $chainedConcatSlot = $this->tryResolveChainedConcatCallArgSlot(
                        $finalArgProbe,
                        $block,
                        $finalChainOps,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $chainedConcatSlot) {
                        if ([] !== $finalChainOps) {
                            $sends = array_merge($sends, $finalChainOps);
                        }
                        $valueSlot = (string) $chainedConcatSlot;
                    } else {
                        $finalProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        $mergeFinalMapped = null;
                        $mergeCalleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                        if (
                            \in_array(
                                $mergeCalleeLower,
                                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                                true
                            )
                        ) {
                            $mergeFinalProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $mergeFinalMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                                $mergeFinalProducers,
                                (int) $argIndex,
                                \count($cfgCallOp->args ?? []),
                                is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                            );
                        }
                        if ($mergeFinalMapped instanceof Op\Expr && null !== $mergeFinalMapped->result) {
                            if (null === $block->slotForOperand($mergeFinalMapped->result)) {
                                foreach ($this->compileExpr($mergeFinalMapped, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $mergeFinalSlot = $block->slotForOperand($mergeFinalMapped->result);
                            if (null !== $mergeFinalSlot) {
                                $valueSlot = (string) $mergeFinalSlot;
                            }
                        } else {
                        $dimFetchFinalSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                            $finalArgProbe,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $dimFetchFinalSlot) {
                            $valueSlot = $dimFetchFinalSlot;
                        } else {
                        $trailingConst = $this->matchNestedArrayTrailingConstFetchCallArgProducer(
                            $finalProducers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex
                        );
                        // ?: Phi-written / scalar ConstFetch-written args keep specialized wiring —
                        // do not rebind via ordinal preceding producers (#22732, #14419).
                        $keepSpecializedCallArgSlot = $this->callArgTemporaryIsPhiWritten($finalArgProbe)
                            || $this->callArgTemporaryIsScalarConstFetchWritten($finalArgProbe);
                        if (
                            $trailingConst instanceof Op\Expr
                            && !$keepSpecializedCallArgSlot
                        ) {
                            if (null === $block->slotForOperand($trailingConst->result)) {
                                foreach ($this->compileExpr($trailingConst, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $trailingConstSlot = $block->slotForOperand($trailingConst->result);
                            if (null !== $trailingConstSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                                $valueSlot = (string) $trailingConstSlot;
                            }
                        } else {
                            $finalProducer = $this->inlineHoistedProducerForCallArgIndex(
                                $cfgCallOp,
                                (int) $argIndex,
                                $finalProducers,
                                $block->orig->children,
                                $block
                            );
                            if (
                                $finalProducer instanceof Op\Expr
                                && null !== $finalProducer->result
                                && !$keepSpecializedCallArgSlot
                            ) {
                                if (null === $block->slotForOperand($finalProducer->result)) {
                                    foreach ($this->compileExpr($finalProducer, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $finalProducerSlot = $block->slotForOperand($finalProducer->result);
                                if (null !== $finalProducerSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                                    $valueSlot = (string) $finalProducerSlot;
                                }
                            }
                        }
                        }
                        }
                    }
                }
            }
        }
        if (null !== $cfgCallOp) {
            $iifeEmitOps = [];
            $iifeSlot = $this->resolveIifeHoistedFuncCallArgProducerSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $iifeEmitOps
            );
            if (null !== $iifeSlot) {
                if ([] !== $iifeEmitOps) {
                    $sends = array_merge($sends, $iifeEmitOps);
                }
                $valueSlot = $iifeSlot;
            }
        }
    }
}
