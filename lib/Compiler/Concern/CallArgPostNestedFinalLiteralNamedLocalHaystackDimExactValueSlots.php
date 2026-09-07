<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Late valueSlot wiring (#36387 / #36403): post-NestedAdjacent final rematch —
 * array_intersect/diff multi-outer EXEC_RETURN, embedded literals, param /
 * post-suppress / named-local slots, lone nested dead-temp, array_pad /
 * array_merge leading INIT_ARRAY, haystack-family sibling, isset/empty /
 * inline-literal dim, substr nested sprintf, unary tail, is_array/count /
 * array_keys, pending dim-fetch fallback, comparison prelude, merge-force
 * finalize, leading ConstFetch FuncCall prelude, immediate property/method,
 * synced coalesce, var_export hoisted scalar, trailing bitmask, null-literal
 * restore, exact hoisted producer, bare named local, array-spread result,
 * and non-Phi ternary sibling.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgNestedAdjacentSiblingVarExportMergeForceConstPreludeValueSlots}
 * so gen-0 split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` /
 * `$sends` / `$inlineArrayLiteralArgWired` by-ref. Mirrors php-src
 * Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgPostNestedFinalLiteralNamedLocalHaystackDimExactValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out bool $inlineArrayLiteralArgWired
     */
    private function resolveCallArgPostNestedFinalLiteralNamedLocalHaystackDimExactValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $callArgOperand,
        mixed $dimFetchSlot,
        mixed $nullLiteralCallArgSlot,
        array &$sends,
        &$valueSlot,
        bool &$inlineArrayLiteralArgWired
    ): void {
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_intersect', 'array_diff', 'array_intersect_key', 'array_diff_key'],
                true
            )
            && $this->countDeadArrayInlineCallArgs($cfgCallOp) >= 2
        ) {
            $multiOuterExec = $this->outerSiblingInlineCallArgProducerExecReturnSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $multiOuterExec) {
                $valueSlot = $multiOuterExec;
            }
        }
        $literalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
        if ($this->isEmbeddedCallLiteralArg($literalProbe)) {
            // Must run after sibling/adjacent wiring — do not alias prior EXEC_RETURN (#16254, array_slice #10229).
            $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
        }
        $sendProbe = $literalProbe;
        $sendName = Block::resolveVariableName($sendProbe);
        if (null !== $sendName && '' !== $sendName) {
            $paramSlot = $block->paramSlotForName($sendName);
            if (null !== $paramSlot) {
                $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, $paramSlot, true);
            }
        }
        $postSuppressAssignSlot = $this->slotForPostErrorSuppressAssignNamedLocalCallArg($sendProbe, $block);
        if (null !== $postSuppressAssignSlot) {
            $valueSlot = (string) $postSuppressAssignSlot;
        }
        // substr(sprintf('%o', fileperms($path)), -N) — adjacent nested wiring must not clobber named path locals (#13636, #16055).
        $sendNamedLocalSlot = $this->namedLocalCallArgSlotIfBound(
            $sendProbe,
            $block,
            $cfgCallOp,
            (int) $argIndex
        ) ?? $this->slotForNamedLocalFromAssignVarOperand($sendProbe, $block);
        if (null !== $sendNamedLocalSlot) {
            if (
                $this->callArgIsDeadInlineTemporary($sendProbe)
                && null !== $valueSlot
                && null !== $cfgCallOp
                && \count($cfgCallOp->args ?? []) >= 2
                && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
            ) {
                // Keep sibling/nested EXEC_RETURN wiring — do not remap dead temps to $path locals (#16480).
            } else {
                $sendNamedAssignDest = $block->slotForNamedAssignDest($sendProbe);
                $valueSlot = null !== $sendNamedAssignDest
                    ? $this->resolveNamedAssignCallArgSlot(
                        $block,
                        (int) $sendNamedAssignDest,
                        $calleeName,
                        (int) $argIndex,
                        $sendProbe
                    )
                    : (string) $this->finalizeOperandSlotForAccess($block, (int) $sendNamedLocalSlot, true);
            }
        }
        // probe('label', in_array(...)) — lone nested callee EXEC_RETURN, not strict/haystack operand (#16312).
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && \is_array($cfgCallOp->args ?? null)
            && $this->callArgIsDeadInlineTemporary($sendProbe)
        ) {
            $deadTempCount = 0;
            foreach ($cfgCallOp->args as $deadArg) {
                if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                    ++$deadTempCount;
                }
            }
            if (1 === $deadTempCount) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $adjacentIndex = $callIndex - 1;
                    while ($adjacentIndex >= 0) {
                        $skip = $block->orig->children[$adjacentIndex] ?? null;
                        if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                            --$adjacentIndex;
                            continue;
                        }
                        break;
                    }
                    $adjacent = $block->orig->children[$adjacentIndex] ?? null;
                    if (
                        ($adjacent instanceof Op\Expr\FuncCall || $adjacent instanceof Op\Expr\NsFuncCall)
                        && $this->isAdjacentNestedFuncCallProducer(
                            $adjacent,
                            $cfgCallOp,
                            $adjacentIndex,
                            $callIndex
                        )
                    ) {
                        $singleNestedExec = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($sends)
                            ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
                        if (null !== $singleNestedExec) {
                            $valueSlot = (string) $singleNestedExec;
                        }
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 0 === (int) $argIndex
            && 'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && $this->callArgIsDeadInlineTemporary($sendProbe)
        ) {
            $padHaystackSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
            if (null !== $padHaystackSlot) {
                $valueSlot = $padHaystackSlot;
                $inlineArrayLiteralArgWired = true;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 0 === (int) $argIndex
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                true
            )
            && null === $valueSlot
            && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
        ) {
            $leadingMergeFinalSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
            if (null !== $leadingMergeFinalSlot) {
                $valueSlot = $leadingMergeFinalSlot;
                $inlineArrayLiteralArgWired = true;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && $this->callArgIsDeadInlineTemporary($sendProbe)
            && $this->callArgUsesHaystackFamilyArrayProducerResolution(
                $cfgCallOp,
                (int) $argIndex,
                $calleeName,
                $sendProbe
            )
            && !(
                0 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
                && null !== $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                    $this->arrayMergeFamilyInlineProducersForCfgCall($block->orig->children, $cfgCallOp),
                    0
                )
            )
        ) {
            $haystackSiblingEmit = [];
            $haystackExecSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $haystackSiblingEmit
            );
            if (null !== $haystackExecSlot) {
                if (
                    0 === (int) $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && $inlineArrayLiteralArgWired
                    && null !== $valueSlot
                ) {
                    // array_merge(['a'=>1], array_keys(...)) — arg #0 stays on leading INIT_ARRAY (#13760, #16418).
                } else {
                    $valueSlot = (string) $haystackExecSlot;
                }
            }
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $finalIssetEmptySlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $finalIssetEmptySlot) {
                $valueSlot = (string) $finalIssetEmptySlot;
            } else {
                $inlineLiteralDimSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $inlineLiteralDimSlot) {
                    $valueSlot = $inlineLiteralDimSlot;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')
        ) {
            $nestedExecSlot = $this->wireSubstrNestedSprintfCallArgSlot($block, $cfgCallOp, (int) $argIndex, $calleeName)
                ?? $this->resolveAdjacentNestedFuncCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                ) ?? $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
            if (null !== $nestedExecSlot) {
                $valueSlot = $nestedExecSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? null)
        ) {
            $valueSlot = $this->compileOperand($cfgCallOp->args[(int) $argIndex], $block, true);
        } elseif (null !== $cfgCallOp && null !== $block->orig) {
            $unaryTailSlot = $this->slotForImmediateUnaryHoistedCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $calleeName
            );
            if (null !== $unaryTailSlot) {
                $valueSlot = $unaryTailSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && 0 === (int) $argIndex
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['is_array', 'count', 'array_keys'],
                true
            )
        ) {
            $arrayBuiltinArg = $cfgCallOp->args[0] ?? $arg;
            if ($arrayBuiltinArg instanceof Operand && $this->callArgIsDeadInlineTemporary($arrayBuiltinArg)) {
                $namedLocalSlot = $this->namedLocalCallArgSlotIfBound(
                    $arrayBuiltinArg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                ) ?? $this->slotForNamedLocalFromAssignVarOperand($arrayBuiltinArg, $block);
                if (null !== $namedLocalSlot) {
                    $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, (int) $namedLocalSlot, true);
                } else {
                    $nestedFileSlot = null;
                    $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
                    if (\is_int($callIndex) && $callIndex > 0 && null !== $block->orig) {
                        $immediate = $block->orig->children[$callIndex - 1] ?? null;
                        if (
                            ($immediate instanceof Op\Expr\FuncCall || $immediate instanceof Op\Expr\NsFuncCall)
                            && $this->isNestedCallArgProducerForConsumer(
                                $immediate,
                                $cfgCallOp,
                                $callIndex - 1,
                                $callIndex,
                                $block->orig->children
                            )
                        ) {
                            $nestedFileSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                                $block,
                                $callIndex - 1,
                                $block->orig->children
                            );
                        }
                    }
                    $nestedFileSlot ??= $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $nestedFileSlot) {
                        $valueSlot = (string) $nestedFileSlot;
                    }
                }
            }
        }
        if (null !== $cfgCallOp && !$this->isEmbeddedCallLiteralArg($arg)) {
            $pendingDimFetchSlot = null;
            if (null !== $dimFetchSlot) {
                // stream_set_blocking($pipes[1], false) — dim-fetch slot is arg #0 only (#18186).
                $pendingDimFetchSlot = $this->lastPendingCallArgArrayDimFetchSlot($block, $sends);
                if (null === $pendingDimFetchSlot) {
                    $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
                }
            } elseif ($this->callArgIsDeadInlineHaystackFamilySlot(
                $cfgCallOp,
                (int) $argIndex,
                $calleeName,
                $arg
            )) {
                $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
            }
            if (null !== $pendingDimFetchSlot) {
                $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                    $block,
                    $cfgCallOp,
                    false
                );
                // The pending scan returns the LAST dim-fetch read, which belongs to the trailing
                // argument. Applying it to every index made t2($r['a'], $r['b']) send $r['b'] twice
                // (#23354). Earlier arguments keep the per-index slot resolved above; the override
                // still runs when nothing else produced one, so it stays a fallback.
                if (
                    null === $immediatePropertySlot
                    && (
                        null === $valueSlot
                        || (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
                    )
                ) {
                    $valueSlot = (string) $pendingDimFetchSlot;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
        ) {
            $comparisonSlot = $this->slotForComparisonPreludeDeadInlineCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $comparisonSlot) {
                $valueSlot = $comparisonSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                true
            )
        ) {
            $mergeForcedSlot = $this->finalizeArrayMergeFamilyCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $mergeForcedSlot) {
                $valueSlot = $mergeForcedSlot;
            }
        }
        if (null !== $cfgCallOp) {
            $leadingConstFuncPreludeEmit = [];
            $leadingConstFuncPreludeSlot = $this->finalizeLeadingConstFetchFuncCallPreludeCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $leadingConstFuncPreludeEmit
            );
            if ([] !== $leadingConstFuncPreludeEmit) {
                $sends = array_merge($sends, $leadingConstFuncPreludeEmit);
            }
            if (null !== $leadingConstFuncPreludeSlot) {
                $valueSlot = $leadingConstFuncPreludeSlot;
            }
        }
        if (null !== $cfgCallOp) {
            $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                $block,
                $cfgCallOp,
                false
            );
            if (
                null !== $immediatePropertySlot
                && $this->callArgIsDeadInlineTemporary($callArgOperand ?? $arg)
            ) {
                $valueSlot = $immediatePropertySlot;
            }
        }
        $syncedFinalArgSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgOperand ?? $arg);
        if (null !== $syncedFinalArgSlot) {
            $valueSlot = (string) $syncedFinalArgSlot;
        }
        if (
            null !== $cfgCallOp
            && 0 === (int) $argIndex
            && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null)
            && null !== $block->orig
        ) {
            $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero(
                $block,
                $cfgCallOp,
                $sends
            );
            if (null !== $hoistedScalarArgSlot) {
                $valueSlot = $hoistedScalarArgSlot;
            }
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $bitmaskArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            $bitmaskSlot = $this->tryResolveInlineBitmaskCallArgSlot(
                $bitmaskArgProbe,
                $block,
                $sends,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null === $bitmaskSlot) {
                $trailingBitmaskArgIndex = $this->trailingNonEmbeddedCallArgIndex($cfgCallOp);
                $bitmaskCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($bitmaskCallIndex) && $bitmaskCallIndex > 0) {
                    $bitmaskImmediate = $block->orig->children[$bitmaskCallIndex - 1] ?? null;
                    if ($bitmaskImmediate instanceof Op\Expr\Assign) {
                        $hoistedRhs = $bitmaskCallIndex > 1
                            ? ($block->orig->children[$bitmaskCallIndex - 2] ?? null)
                            : null;
                        if (
                            $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
                        ) {
                            $bitmaskImmediate = $hoistedRhs;
                        } else {
                            $bitmaskImmediate = $bitmaskImmediate->expr;
                        }
                    }
                    if (
                        (int) $argIndex === $trailingBitmaskArgIndex
                        && (
                            $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseXor
                        )
                        && (
                            $this->callArgIsDeadInlineTemporary($bitmaskArgProbe)
                            || $this->callArgIsAssignInCallOperand($bitmaskArgProbe)
                        )
                        && !$this->callArgOperandExpectsArrayProducer($bitmaskArgProbe)
                    ) {
                        $namedDest = $this->slotForHoistedAssignInCallNamedDest($block, $cfgCallOp);
                        if (null !== $namedDest) {
                            $bitmaskSlot = $namedDest;
                        } elseif (null === $block->slotForOperand($bitmaskImmediate->result)) {
                            foreach ($this->compileExpr($bitmaskImmediate, $block) as $op) {
                                $sends[] = $op;
                            }
                            $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                        } else {
                            $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                        }
                    }
                }
            }
            if (
                null !== $bitmaskSlot
                && (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
            ) {
                $valueSlot = (string) $bitmaskSlot;
            }
        }
        if (null !== $nullLiteralCallArgSlot) {
            $valueSlot = $nullLiteralCallArgSlot;
        }
        // Last word to the exact argument->producer link (#23354). Every heuristic above resolves
        // a hoisted argument from the statement before the call, which is only ever the TRAILING
        // argument's producer; php-cfg records the real producer as the argument temporary's sole
        // writer, so this is the one mapping that is right by construction rather than by shape.
        $exactSlot = $this->exactHoistedCallArgProducerSlot($block, $cfgCallOp, (int) $argIndex, $sends);
        if (null !== $exactSlot) {
            $valueSlot = $exactSlot;
        }
        // Bare named locals ($x as call arg): CV assign-dest must win over later heuristics
        // that re-bind the call-site clone Temporary to a fresh empty slot (#23893, re-#23354).
        $bareLocalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
        if (
            $bareLocalProbe instanceof Operand
            && null !== Block::resolveVariableName($bareLocalProbe)
            && !$this->callArgIsDeadInlineTemporary($bareLocalProbe)
        ) {
            $bareNamedDest = $block->slotForNamedAssignDest($bareLocalProbe);
            if (null !== $bareNamedDest) {
                $valueSlot = $this->resolveNamedAssignCallArgSlot(
                    $block,
                    (int) $bareNamedDest,
                    $calleeName,
                    (int) $argIndex,
                    $bareLocalProbe
                );
            } elseif (null === $valueSlot) {
                $valueSlot = $this->compileOperand($bareLocalProbe, $block, true);
            }
        }
        // [...new ArrayIterator([...])] as call arg: nested ctor Array_ slot may win over
        // the spread INIT_ARRAY that sits after FUNCCALL_EXEC_RETURN (#24645).
        $callArgProbeFinal = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
        if (
            null !== $cfgCallOp
            && $callArgProbeFinal instanceof Operand
            && $this->callArgIsDeadInlineTemporary($callArgProbeFinal)
            && (
                $this->callArgOperandExpectsArrayProducer($callArgProbeFinal)
                || $this->callArgIsDeadUnknownOrMixedTemporary($callArgProbeFinal)
            )
        ) {
            $spreadResultSlot = $this->slotForArraySpreadResultAfterLastExecReturn($block, $sends);
            if (null !== $spreadResultSlot) {
                $valueSlot = $spreadResultSlot;
            }
        }
        // array_merge([1], $x ? [2] : [3]) / twoway(FLAG, 'C' ?: 'D') — non-Phi sibling of
        // ?: must keep Array_/ConstFetch writer slot, not the merge phi (#25337).
        if (null !== $cfgCallOp && \is_array($cfgCallOp->args ?? null)) {
            $ternarySiblingProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if ($ternarySiblingProbe instanceof Operand) {
                $ternarySiblingSlot = $this->resolveNonPhiSiblingOfTernaryCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $ternarySiblingProbe,
                    $sends
                );
                if (null !== $ternarySiblingSlot) {
                    $valueSlot = $ternarySiblingSlot;
                }
            }
        }
    }
}
