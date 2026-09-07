<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Late valueSlot wiring (#36387 / #36403): adjacent nested FuncCall rematch,
 * property/method/sibling send slots, var_export producers, array_combine /
 * array_merge-family force finalize, proc_open re-wire, ConstFetch prelude,
 * array_map FCC/closure callback, and forced-sibling EXEC_RETURN ordinals.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgColumnCombineCoalesceStreamFilterSpaceshipAndFilterFamilyValueSlots}
 * so gen-0 split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` /
 * `$sends` by-ref. Mirrors php-src Zend/zend_compile.c call-arg send operand
 * wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgNestedAdjacentSiblingVarExportMergeForceConstPreludeValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgNestedAdjacentSiblingVarExportMergeForceConstPreludeValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $arraySliceSlot,
        bool $inlineArrayLiteralArgWired,
        bool $hoistedEnumPropertyCallArgSlotWired,
        array &$sends,
        &$valueSlot
    ): void {
        // probe('label', in_array(..., g(), true)) — nested callee return, not inner ConstFetch (#14237, #16013).
        // array_slice([..], array_search(...)) — keep resolveArraySliceInlineCallArgSlot haystack/offset (#13684, #16023).
        $nestedCallArgSlot = null;
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && null === $arraySliceSlot
            && !$inlineArrayLiteralArgWired
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
            && !$this->immediatePredecessorIsInlineBitmaskProducer($cfgCallOp, $block)
        ) {
            $nestedCallArgSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            // Do not clobber a ClassConstFetch/flag already wired for this arg
            // (new CachingIterator(new ArrayIterator(...), CachingIterator::FULL_CACHE) — #19769).
            if (null !== $nestedCallArgSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                $valueSlot = $nestedCallArgSlot;
            }
        }
        if (
            null !== $nestedCallArgSlot
            || null === $cfgCallOp
            || null === $block->orig
            || !$this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
        ) {
            // keep resolved slot
        } elseif ($this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)) {
            // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not adjacent FuncCall (#16028).
        } elseif (
            null === $valueSlot
            && !(
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            )
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $prevStmt = $block->orig->children[$callIndex - 1] ?? null;
                if ($prevStmt instanceof Op\Expr\FuncCall || $prevStmt instanceof Op\Expr\NsFuncCall) {
                    $adjacentExec = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                        $block,
                        $callIndex - 1,
                        $block->orig->children
                    );
                    if (null !== $adjacentExec) {
                        $nestedCallArgSlot = (string) $adjacentExec;
                        $valueSlot = $nestedCallArgSlot;
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null === $nestedCallArgSlot
            && !($cfgCallOp instanceof Op\Expr\MethodCall)
            && !($cfgCallOp instanceof Op\Expr\NullsafeMethodCall)
        ) {
            $immediatePropertyOrMethodSlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                $block,
                $cfgCallOp,
                false
            );
            if (null !== $immediatePropertyOrMethodSlot) {
                // Property/method prelude — do not clobber ClassConstFetch/flag wiring (#19769).
                if (!$hoistedEnumPropertyCallArgSlotWired) {
                    $valueSlot = $immediatePropertyOrMethodSlot;
                }
            } elseif (null !== $block->orig) {
                if (null === $valueSlot) {
                    $siblingSendSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                    if (null !== $siblingSendSlot) {
                        $valueSlot = $siblingSendSlot;
                    }
                }
            }
        } elseif (null !== $cfgCallOp && null !== $block->orig) {
            $siblingSendSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
            if (null !== $siblingSendSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                $valueSlot = $siblingSendSlot;
            }
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
            } else {
                $varExportProducerSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                    $block,
                    $cfgCallOp,
                    0
                );
                if (null !== $varExportProducerSlot) {
                    $valueSlot = $varExportProducerSlot;
                }
            }
        }
        if (
            null !== $cfgCallOp
            && 0 === (int) $argIndex
            && 'var_export' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            $varExportArg = $cfgCallOp->args[0] ?? $arg;
            if (
                $varExportArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($varExportArg)
                && $this->callArgOperandExpectsArrayProducer($varExportArg)
                && null !== $block->orig
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                $stmtBeforeVarExport = \is_int($callIndex)
                    ? ($block->orig->children[$callIndex - 1] ?? null)
                    : null;
                if (!$stmtBeforeVarExport instanceof Op\Expr\BinaryOp\Plus) {
                    $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                        $arrayArgSlot = $this->slotForRecentInitArrayCallArg($block);
                        if (null === $arrayArgSlot) {
                            $arrayArgSlot = $block->slotForOperand($stmtBeforeArray->result);
                        }
                        if (null === $arrayArgSlot) {
                            foreach ($this->compileArrayLiteral($stmtBeforeArray, $block) as $op) {
                                $sends[] = $op;
                            }
                            $arrayArgSlot = $this->slotForRecentInitArrayCallArg($block)
                                ?? $block->slotForOperand($stmtBeforeArray->result);
                        }
                        if (null !== $arrayArgSlot) {
                            $valueSlot = (string) $arrayArgSlot;
                        }
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
            && null !== $block->orig
        ) {
            $combineForcedSlot = $this->finalizeArrayCombineCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $combineForcedSlot) {
                $valueSlot = $combineForcedSlot;
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
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && (int) $argIndex > 0
            && \is_array($cfgCallOp->args ?? null)
            && (int) $argIndex === \count($cfgCallOp->args) - 1
            && $this->callHasNamedVariableArgument($cfgCallOp)
        ) {
            $mixedCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($mixedCallIndex) && $mixedCallIndex > 0) {
                $adjacentProducer = $block->orig->children[$mixedCallIndex - 1] ?? null;
                if ($adjacentProducer instanceof Op\Expr\FuncCall || $adjacentProducer instanceof Op\Expr\NsFuncCall) {
                    $adjacentExec = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                        $block,
                        $mixedCallIndex - 1,
                        $block->orig->children
                    );
                    if (null !== $adjacentExec) {
                        $valueSlot = (string) $adjacentExec;
                    }
                }
            }
        }
        if ('proc_open' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '') && null !== $cfgCallOp) {
            $procOpenFinalSlot = $this->resolveProcOpenInlineCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $procOpenFinalSlot) {
                $valueSlot = $procOpenFinalSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && !$this->arrayCombineSkipsSiblingFuncExecArgSlot($cfgCallOp, (int) $argIndex, $block)
        ) {
            $constPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $constPreludeSlot) {
                $valueSlot = $constPreludeSlot;
            }
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            $firstSibling = \is_int($callIndex)
                ? $this->firstSiblingInlineFuncCallProducerIndexImpl($callIndex, $block->orig->children)
                : null;
            if (\is_int($callIndex) && \is_int($firstSibling)) {
                if (
                    0 === (int) $argIndex
                    && 'array_map' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
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
                    if (null === $valueSlot) {
                        for ($scan = \count($block->opCodes) - 1; $scan >= 0; --$scan) {
                            $scanOp = $block->opCodes[$scan];
                            if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                                $valueSlot = (string) $scanOp->arg1;
                                break;
                            }
                        }
                    }
                }
                if (
                    0 === (int) $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && ($mergeLeadingArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block)) instanceof Op\Expr\Array_
                ) {
                    // array_merge(['a'=>1], $o) — arg #0 is stmt-before Array_, not distant sibling EXEC (#16299).
                    if (null === $block->slotForOperand($mergeLeadingArray->result)) {
                        foreach ($this->compileArrayLiteral($mergeLeadingArray, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $mergeLeadSlot = $block->slotForOperand($mergeLeadingArray->result)
                        ?? $this->slotForRecentInitArrayCallArg($block);
                    if (null !== $mergeLeadSlot) {
                        $valueSlot = (string) $mergeLeadSlot;
                    }
                }
                $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
                    $firstSibling,
                    $callIndex,
                    $block->orig->children
                );
                $producerOrdinal = $this->inlineHoistedProducerSlotIndexForCallArg(
                    $cfgCallOp->args ?? $args,
                    (int) $argIndex,
                    $block,
                    $cfgCallOp
                );
                $consumerName = $this->resolveCfgFuncCallName($cfgCallOp) ?? $calleeName;
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($consumerName);
                $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                if (
                    $callbackArgIndex >= 0
                    && 2 === \count($cfgCallOp->args ?? [])
                    && (int) $argIndex === $callbackArgIndex
                    && ($leadingCallback instanceof Op\Expr\ArrowFunction
                        || $leadingCallback instanceof Op\Expr\Closure
                        || $leadingCallback instanceof Op\Expr\FirstClassCallable)
                ) {
                    // array_map(intval(...), str_split(str_repeat(...))) — ordinal ExecReturn must not steal FCC callback (#15487, #16279).
                    if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                        $callbackSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                    } else {
                        $callbackSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                    }
                    if (null !== $callbackSlot) {
                        $valueSlot = (string) $callbackSlot;
                    }
                } elseif (
                    null !== $producerOrdinal
                    && $producerOrdinal < $chainProducerCount
                    && null === $valueSlot
                    && !$this->callArgHasHoistedConstPrelude($cfgCallOp, (int) $argIndex, $block)
                ) {
                    $execReturnCount = $block->funccallExecReturnCount();
                    $execOrdinal = $execReturnCount - $chainProducerCount + $producerOrdinal;
                    $forcedSiblingSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                        $block,
                        $execOrdinal
                    );
                    if (null !== $forcedSiblingSlot) {
                        $valueSlot = (string) $forcedSiblingSlot;
                    }
                }
            }
            $forcedSiblingSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
            if (null === $forcedSiblingSlot) {
                $forcedSiblingOps = [];
                $forcedSiblingSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $forcedSiblingOps
                );
                if ([] !== $forcedSiblingOps) {
                    $sends = array_merge($sends, $forcedSiblingOps);
                }
            }
            if (null !== $forcedSiblingSlot) {
                if (
                    0 === (int) $argIndex
                    && (
                        $inlineArrayLiteralArgWired
                        || $this->arrayMergeFamilyLeadingInlineArrayArgUsesHoistedArray($cfgCallOp, (int) $argIndex, $block)
                    )
                ) {
                    if (null === $valueSlot) {
                        $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        $leadingMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                            $mergeProducers,
                            0,
                            \count($cfgCallOp->args ?? []),
                            is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                        );
                        if ($leadingMapped instanceof Op\Expr\Array_) {
                            $leadingSlot = $block->slotForOperand($leadingMapped->result)
                                ?? $this->slotForInitArrayOrdinal($block, 0, $sends);
                            if (null !== $leadingSlot) {
                                $valueSlot = (string) $leadingSlot;
                            }
                        }
                    }
                } elseif (
                    null === $valueSlot
                    && null === $constPreludeSlot
                    && !$this->callArgHasHoistedConstPrelude($cfgCallOp, (int) $argIndex, $block)
                ) {
                    $valueSlot = (string) $forcedSiblingSlot;
                }
            }
        }
    }
}
