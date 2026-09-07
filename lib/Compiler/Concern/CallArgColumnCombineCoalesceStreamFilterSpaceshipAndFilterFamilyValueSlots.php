<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-assign/proc_open/iife early valueSlot wiring (#36387 / #36403): array_column /
 * array_combine finalize, stmt-coalesce, proc_open rematch, stream_context options
 * outermost INIT_ARRAY, filter_var/filter_input options arrays, filter_input finalize,
 * spaceship comparison prelude, chained ArrayDimFetch, date_sun / array_splice /
 * mbstring unary offset, final named-local bind, and filter_input/filter_var trailing
 * ConstFetch / BitwiseOr rematch.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots} so
 * gen-0 split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends`
 * by-ref. Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgColumnCombineCoalesceStreamFilterSpaceshipAndFilterFamilyValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgColumnCombineCoalesceStreamFilterSpaceshipAndFilterFamilyValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array &$sends,
        &$valueSlot
    ): void {
        if ('array_column' === strtolower($calleeName ?? '') && null !== $cfgCallOp && null !== $block->orig) {
            $arrayColumnSlot = $this->finalizeArrayColumnCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $arrayColumnSlot) {
                $valueSlot = $arrayColumnSlot;
            }
        }
        if (
            'array_combine' === strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && null !== $cfgCallOp
            && null !== $block->orig
        ) {
            $arrayCombineSlot = $this->finalizeArrayCombineCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $arrayCombineSlot) {
                $valueSlot = $arrayCombineSlot;
            }
        }
        $valueSlot = $this->finalizeStmtCoalesceCallArgSlot(
            $arg,
            $block,
            $cfgCallOp,
            (int) $argIndex,
            $valueSlot
        );
        if ('proc_open' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '') && null !== $cfgCallOp) {
            $procOpenSlot = $this->resolveProcOpenInlineCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $procOpenSlot) {
                $valueSlot = $procOpenSlot;
            }
        }
        if (null !== $cfgCallOp) {
            $streamContextCall = $this->resolveCfgFuncCallName($cfgCallOp);
            $streamContextOptionsArgIndex = match ($streamContextCall) {
                'stream_context_set_options' => 1,
                'stream_context_create', 'stream_context_set_default', 'stream_context_get_default' => 0,
                default => null,
            };
            if (
                null !== $streamContextOptionsArgIndex
                && (int) $argIndex === $streamContextOptionsArgIndex
            ) {
                $contextOptionsArg = $cfgCallOp->args[$streamContextOptionsArgIndex] ?? $arg;
                if (
                    $this->callArgIsDeadInlineTemporary($contextOptionsArg)
                    && $this->callArgOperandExpectsArrayProducer($contextOptionsArg)
                ) {
                    $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                    if (null !== $outerSlot) {
                        $valueSlot = $outerSlot;
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && 'filter_var' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && 2 === (int) $argIndex
        ) {
            $optionsArg = $cfgCallOp->args[2] ?? $arg;
            if ($this->callArgIsDeadInlineTemporary($optionsArg)) {
                // Typed array[] options (#12007). Unknown-typed nested options with
                // FILTER_FLAG_* ConstFetch elements still need the outermost INIT_ARRAY (#22772).
                $useOutermostOptionsArray = $this->callArgOperandExpectsArrayProducer($optionsArg);
                if (!$useOutermostOptionsArray && null !== $block->orig) {
                    $useOutermostOptionsArray = null !== $this->splitLeadingConstFetchWithNestedArrayLiteralChain(
                        $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    );
                }
                if ($useOutermostOptionsArray) {
                    $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                    if (null !== $outerSlot) {
                        $valueSlot = $outerSlot;
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && 3 === (int) $argIndex
        ) {
            $optionsArg = $cfgCallOp->args[3] ?? $arg;
            if ($this->callArgIsDeadInlineTemporary($optionsArg)) {
                $useOutermostOptionsArray = $this->callArgOperandExpectsArrayProducer($optionsArg);
                if (!$useOutermostOptionsArray && null !== $block->orig) {
                    $useOutermostOptionsArray = null !== $this->splitLeadingConstFetchWithNestedArrayLiteralChain(
                        $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    );
                }
                if ($useOutermostOptionsArray) {
                    $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                    if (null !== $outerSlot) {
                        $valueSlot = $outerSlot;
                    }
                }
            }
        }
        $filterInputSlot = $this->finalizeFilterInputCallArgSlot(
            $block,
            $cfgCallOp,
            (int) $argIndex,
            $sends
        );
        if (null !== $filterInputSlot) {
            $valueSlot = $filterInputSlot;
        }
        // var_dump(E::A <=> E::B) — immediate spaceship prelude wins over hoisted enum temps (#10203).
        if (null !== $cfgCallOp && null !== $block->orig && 0 === (int) $argIndex) {
            $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if ($this->callArgIsDeadInlineTemporary($callArgProbe)) {
                $cfgCallIndex = null;
                foreach ($block->orig->children as $ci => $cfgChild) {
                    if ($cfgChild === $cfgCallOp) {
                        $cfgCallIndex = $ci;
                        break;
                    }
                }
                if (null !== $cfgCallIndex && $cfgCallIndex > 0) {
                    $immediatePrelude = $block->orig->children[$cfgCallIndex - 1] ?? null;
                    $comparisonPrelude = null;
                    if ($this->isComparisonInlineCallArgProducer($immediatePrelude)) {
                        $comparisonPrelude = $immediatePrelude;
                    } elseif (
                        $cfgCallIndex > 1
                        && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($immediatePrelude)
                    ) {
                        $candidate = $block->orig->children[$cfgCallIndex - 2] ?? null;
                        if ($this->isComparisonInlineCallArgProducer($candidate)) {
                            $comparisonPrelude = $candidate;
                        }
                    }
                    if (
                        $comparisonPrelude instanceof Op\Expr
                        && null !== $comparisonPrelude->result
                    ) {
                        if (null === $block->slotForOperand($comparisonPrelude->result)) {
                            foreach ($this->compileExpr($comparisonPrelude, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $comparisonSlot = $block->slotForOperand($comparisonPrelude->result);
                        if (null !== $comparisonSlot) {
                            $valueSlot = (string) $comparisonSlot;
                        }
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && null === $this->findStmtCoalesceImmediatelyBeforeFuncCall($cfgCallOp, $block)
        ) {
            $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer(
                $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                (int) $argIndex
            );
            if ($chainedDimFetch instanceof Op\Expr && null !== $chainedDimFetch->result) {
                if (null === $block->slotForOperand($chainedDimFetch->result)) {
                    foreach ($this->compileExpr($chainedDimFetch, $block) as $op) {
                        $sends[] = $op;
                    }
                }
                $chainSlot = $block->slotForOperand($chainedDimFetch->result);
                if (null === $chainSlot) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $dimFetches = array_values(array_filter(
                        $producers,
                        static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ArrayDimFetch
                    ));
                    if (
                        \count($dimFetches) >= 2
                        && $this->arrayDimFetchesFormProducerChain($dimFetches)
                    ) {
                        $chainSlot = $this->pendingCallArgArrayDimFetchSlot(
                            $block,
                            $sends,
                            \count($dimFetches) - 1
                        );
                    }
                }
                if (null !== $chainSlot) {
                    $valueSlot = (string) $chainSlot;
                }
            }
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $dateSunSlot = $this->wireDateSunFuncHoistedCallArgSlot($block, $cfgCallOp, (int) $argIndex);
            if (null !== $dateSunSlot) {
                $valueSlot = $dateSunSlot;
            }
            if (null === $valueSlot) {
                $arraySpliceSlot = $this->wireArraySpliceUnaryOffsetReplacementCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $arraySpliceSlot) {
                    $valueSlot = $arraySpliceSlot;
                }
            }
            if (null === $valueSlot) {
                $mbstringSlot = $this->wireMbstringUnaryOffsetNullLengthCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $mbstringSlot) {
                    $valueSlot = $mbstringSlot;
                }
            }
        }
        $finalCallArgProbe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $finalCallArgProbe = $cfgCallOp->args[(int) $argIndex];
        }
        $finalNamedSlot = $this->namedLocalCallArgSlotIfBound(
            $finalCallArgProbe,
            $block,
            $cfgCallOp,
            (int) $argIndex
        ) ?? $this->slotForNamedLocalFromAssignVarOperand($finalCallArgProbe, $block);
        if (null === $finalNamedSlot) {
            $finalVarName = Block::resolveVariableName($finalCallArgProbe);
            if (null !== $finalVarName && '' !== $finalVarName) {
                $finalNamedSlot = $block->slotIndexForVariableName($finalVarName);
            }
        }
        if (null !== $finalNamedSlot) {
            $skipFinalNamedForSiblingExec = $this->callArgIsDeadInlineTemporary($finalCallArgProbe)
                && null !== $cfgCallOp
                && \count($cfgCallOp->args ?? []) >= 2
                && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp);
            if (!$skipFinalNamedForSiblingExec) {
                $finalNamedAssignDest = $block->slotForNamedAssignDest($finalCallArgProbe);
                $valueSlot = null !== $finalNamedAssignDest
                    ? $this->resolveNamedAssignCallArgSlot(
                        $block,
                        (int) $finalNamedAssignDest,
                        $calleeName,
                        (int) $argIndex,
                        $finalCallArgProbe
                    )
                    : (string) $this->finalizeOperandSlotForAccess($block, (int) $finalNamedSlot, true);
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && (0 === (int) $argIndex || 2 === (int) $argIndex)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex) {
                $wantPrefix = 0 === (int) $argIndex ? 'input_' : 'filter_';
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $child = $block->orig->children[$i];
                    if (!$child instanceof Op\Expr\ConstFetch) {
                        if ($child instanceof Op\Expr\Assign) {
                            break;
                        }
                        continue;
                    }
                    $name = strtolower($this->staticNameFromOperand($child->name) ?? '');
                    if (!str_starts_with($name, $wantPrefix)) {
                        continue;
                    }
                    $slot = $block->slotForOperand($child->result);
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                    break;
                }
            }
        }
        // filter_var($v, FILTER_*, FLAGS|FLAGS) — hoisted filter const is not the trailing BitwiseOr (#17410).
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'filter_var' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && \is_array($cfgCallOp->args ?? null)
            && 3 === \count($cfgCallOp->args)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex) {
                if (1 === (int) $argIndex) {
                    for ($i = $callIndex - 1; $i >= 0; --$i) {
                        $child = $block->orig->children[$i];
                        if (!$child instanceof Op\Expr\ConstFetch) {
                            if ($child instanceof Op\Expr\Assign) {
                                break;
                            }
                            continue;
                        }
                        $name = strtolower($this->staticNameFromOperand($child->name) ?? '');
                        if (
                            !str_starts_with($name, 'filter_')
                            || $this->isFilterVarOptionFlagConstName($name)
                        ) {
                            continue;
                        }
                        if (null === $block->slotForOperand($child->result)) {
                            foreach ($this->compileExpr($child, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $filterSlot = $block->slotForOperand($child->result);
                        if (null !== $filterSlot) {
                            $valueSlot = (string) $filterSlot;
                        }
                        break;
                    }
                } elseif (2 === (int) $argIndex) {
                    $optionsArg = $cfgCallOp->args[2] ?? $arg;
                    if (
                        $this->callArgIsDeadInlineTemporary($optionsArg)
                        && !$this->callArgOperandExpectsArrayProducer($optionsArg)
                    ) {
                        $immediate = $block->orig->children[$callIndex - 1] ?? null;
                        if (
                            $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor
                            || $immediate instanceof Op\Expr\ConstFetch
                        ) {
                            if (null === $block->slotForOperand($immediate->result)) {
                                foreach ($this->compileExpr($immediate, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $optionsSlot = $block->slotForOperand($immediate->result);
                            if (null !== $optionsSlot) {
                                $valueSlot = (string) $optionsSlot;
                            }
                        }
                    }
                }
            }
        }
    }
}
