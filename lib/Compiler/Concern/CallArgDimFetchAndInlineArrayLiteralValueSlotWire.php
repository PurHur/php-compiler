<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;

/**
 * DimFetch / inline Array_|FuncCall valueSlot wiring after null-literal+coalesce
 * early slots (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$inlineArray` / `$inlineArrayLiteralArgWired`
 * by-ref; `$tookDimOrInlineArrayBranch` is true when a dimFetch / inline FuncCall /
 * inline Array_ branch ran (caller skips residual else resolvers). Mirrors php-src
 * Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgDimFetchAndInlineArrayLiteralValueSlotWire
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $inlineArray may remap leading merge Array_
     * @param-out bool $inlineArrayLiteralArgWired
     * @param-out bool $tookDimOrInlineArrayBranch
     */
    private function wireCallArgDimFetchAndInlineArrayLiteralValueSlot(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?Op $cfgCallOp,
        mixed $callArgOperand,
        ?string $dimFetchSlot,
        mixed $unpackFlag,
        array &$sends,
        &$valueSlot,
        &$inlineArray,
        &$inlineArrayLiteralArgWired,
        &$tookDimOrInlineArrayBranch
    ): void {
        $tookDimOrInlineArrayBranch = false;
        if (
            null !== $dimFetchSlot
            && null === $valueSlot
            && !$this->callArgIsCoalesceMergeProducer($callArgOperand, $block, $cfgCallOp, (int) $argIndex)
            && !$this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, (int) $argIndex)
        ) {
            $tookDimOrInlineArrayBranch = true;
            $valueSlot = $dimFetchSlot;
        } elseif (
            null === $valueSlot
            && null !== $inlineArray
            && (
                $inlineArray instanceof Op\Expr\FuncCall
                || $inlineArray instanceof Op\Expr\NsFuncCall
            )
        ) {
            $tookDimOrInlineArrayBranch = true;
            if (
                0 === (int) $argIndex
                && null !== $cfgCallOp
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
                && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
            ) {
                // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not nested keys (#13760, #16418).
            } else {
            // array_combine(array_keys(...), [...]) — sibling FuncCall, not Array_ literal (#15558, #16097).
            if (null === $block->slotForOperand($inlineArray->result)) {
                foreach ($this->compileExpr($inlineArray, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $funcOrdinal = 0;
            if (null !== $cfgCallOp && null !== $block->orig) {
                foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                ) as $producer) {
                    if ($producer === $inlineArray) {
                        break;
                    }
                    if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                        ++$funcOrdinal;
                    }
                }
            }
            $valueSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($inlineArray->result, $block, true);
            }
            }
        } elseif (null !== $inlineArray) {
            $tookDimOrInlineArrayBranch = true;
            if (
                0 === (int) $argIndex
                && null !== $cfgCallOp
                && null !== $block->orig
                && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
            ) {
                $mergeCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (is_int($mergeCallIndex)) {
                    for ($mai = 0; $mai < $mergeCallIndex; ++$mai) {
                        $mergeChild = $block->orig->children[$mai] ?? null;
                        if ($mergeChild instanceof Op\Expr\Array_) {
                            $inlineArray = $mergeChild;
                            break;
                        }
                    }
                }
            }
            $callArgProbeForArray = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $callArgOperand;
            if (
                0 === (int) $argIndex
                && null !== $cfgCallOp
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
                && $inlineArray instanceof Op\Expr\Array_
                && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
            ) {
                $leadingMergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                if (null !== $leadingMergeSlot) {
                    $valueSlot = $leadingMergeSlot;
                    $inlineArrayLiteralArgWired = true;
                }
            }
            if (!$inlineArrayLiteralArgWired) {
            $existingArraySlot = null;
            // array_combine([...], [...]) — sibling Array_ producers map by index; never reuse "recent" init slot (#16080, #10214).
            // array_reduce([...], fn, [...]) — same: initial [] must not steal input INIT_ARRAY (#5626).
            $arrayCombineSiblingArray = null !== $cfgCallOp
                && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
                && $inlineArray instanceof Op\Expr\Array_;
            $arrayReduceSiblingArrays = null !== $cfgCallOp
                && 'array_reduce' === $this->resolveCfgFuncCallName($cfgCallOp)
                && $inlineArray instanceof Op\Expr\Array_
                && null !== $block->orig
                && $this->arrayReduceCfgCallHasMultipleInlineArrayProducers($block, $cfgCallOp);
            if (
                $arrayCombineSiblingArray
                || $arrayReduceSiblingArrays
                || !$this->callArgIsDeadInlineTemporary($callArgProbeForArray)
                || !$this->callArgOperandExpectsArrayProducer($callArgProbeForArray)
            ) {
                if (($arrayCombineSiblingArray || $arrayReduceSiblingArrays) && null !== $cfgCallOp) {
                    // Sequential array_combine(array(...), array(...)) — operand slots from the first
                    // call must not be reused via slotForOperand (#17629, re-#16080, #10214).
                    // array_reduce([...], fn, []) — map each Array_ to its INIT_ARRAY ordinal (#5626).
                    $existingArraySlot = $this->slotForInitArrayProducerBeforeCfgCall(
                        $block,
                        $cfgCallOp,
                        $inlineArray,
                        $sends
                    );
                } else {
                    $existingArraySlot = $block->slotForOperand($inlineArray->result);
                }
            }
            if (
                null === $existingArraySlot
                && $cfgCallOp instanceof Op\Expr\New_
                && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray
            ) {
                $recentInitArraySlot = $this->slotForRecentInitArrayCallArg($block);
                if (null !== $recentInitArraySlot) {
                    $existingArraySlot = (int) $recentInitArraySlot;
                }
            }
            if (null !== $existingArraySlot) {
                $valueSlot = (string) $existingArraySlot;
                $inlineArrayLiteralArgWired = true;
            } else {
                $arrayOps = $this->compileArrayLiteral($inlineArray, $block);
                if ([] !== $arrayOps) {
                    $sends = array_merge($sends, $arrayOps);
                }
                $initSlot = $this->slotFromInitArrayLiteralOps($arrayOps);
                if (
                    null === $initSlot
                    && 0 === (int) $argIndex
                    && null === $unpackFlag
                    && null !== $cfgCallOp
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && $inlineArray instanceof Op\Expr\Array_
                ) {
                    $initSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                }
                if (
                    null === $initSlot
                    && $this->callArgIsDeadInlineTemporary($callArgProbeForArray)
                    && $this->callArgOperandExpectsArrayProducer($callArgProbeForArray)
                    && !$arrayCombineSiblingArray
                    && !$arrayReduceSiblingArrays
                ) {
                    $initSlot = $this->slotForRecentInitArrayCallArg($block);
                }
                if (
                    null === $initSlot
                    && $cfgCallOp instanceof Op\Expr\New_
                    && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray
                ) {
                    $initSlot = $this->slotForRecentInitArrayCallArg($block);
                }
                $valueSlot = $initSlot ?? $this->compileOperand($inlineArray->result, $block, true);
                if (null !== $valueSlot) {
                    $inlineArrayLiteralArgWired = true;
                }
            }
            }
        }
    }
}
