<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-named-assign early valueSlot wiring (#36387 / #36403): dead-array
 * rematch (guarded by `$namedAssignDest`), preg_replace_callback_array init,
 * closure callback, null-literal register, hoisted array-pointer builtin,
 * trailing comparison / concat / arithmetic chains, var_export nested, and
 * ConstFetch/ClassConstFetch producer rematch.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgSiblingMergeEmbeddedIssetLogicalNamedAssignValueSlots} so gen-0
 * split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends` by-ref.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgDeadArrayComparisonConcatPointerValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgDeadArrayComparisonConcatPointerValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $dimFetchSlot,
        mixed $namedAssignDest,
        bool $inlineArrayLiteralArgWired,
        array &$sends,
        &$valueSlot
    ): void {
        if (null === $namedAssignDest) {
        if (
            null !== $cfgCallOp
            && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
            && $this->countDeadArrayInlineCallArgs($cfgCallOp) >= 1
            && null !== $block->orig
            && !$this->precedingInlineCallArgHasPlusOrConcatProducer($block->orig->children, $cfgCallOp)
            && null === $this->nestedInlineFuncCallProducerForCallArg($block, $cfgCallOp, (int) $argIndex)
        ) {
            $producers = null !== $block->orig
                ? $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                )
                : [];
            $deadArrayArgCount = $this->countDeadArrayInlineCallArgs($cfgCallOp);
            if ($deadArrayArgCount >= 2) {
                $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                    $producers,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                );
                if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $matchedSlot = $block->slotForOperand($matched->result);
                    if (null !== $matchedSlot) {
                        $valueSlot = (string) $matchedSlot;
                    }
                }
            } else {
                $lastProducer = $producers[\count($producers) - 1] ?? null;
                $hasArrayUnionPlus = false;
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\BinaryOp\Plus) {
                        $hasArrayUnionPlus = true;
                        break;
                    }
                }
                // Array union arg is Plus.result, not the trailing INIT_ARRAY temp (#10490, #12763).
                if (!$hasArrayUnionPlus && !$lastProducer instanceof Op\Expr\BinaryOp\Plus) {
                    $immediateArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, (int) $argIndex, $block);
                    if (
                        $immediateArray instanceof Op\Expr\Array_
                        && null !== $dimFetchSlot
                    ) {
                        // Inline literal dim-fetch feeds the call arg — not the array temp (#16462).
                    } elseif ($immediateArray instanceof Op\Expr\Array_) {
                        $immediateSlot = $block->slotForOperand($immediateArray->result);
                        if (null === $immediateSlot) {
                            foreach ($this->compileExpr($immediateArray, $block) as $op) {
                                $sends[] = $op;
                            }
                            $immediateSlot = $block->slotForOperand($immediateArray->result);
                        }
                        if (null !== $immediateSlot) {
                            $valueSlot = (string) $immediateSlot;
                        }
                    } else {
                        $resolvedSlot = $this->slotForDeadInlineArrayOrCallResultCallArg($block, $cfgCallOp, (int) $argIndex);
                        if (null !== $resolvedSlot) {
                            $valueSlot = $resolvedSlot;
                        }
                    }
                }
            }
        }
        }
        if (
            null !== $cfgCallOp
            && 0 === $argIndex
            && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            $initArraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block);
            if (null !== $initArraySlot) {
                $valueSlot = $initArraySlot;
            }
        } else            if (
            null !== $cfgCallOp
            && null === $valueSlot
            && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && !$this->callArgIsNullLiteral(
                $cfgCallOp->args[(int) $argIndex] ?? $arg,
                $cfgCallOp,
                (int) $argIndex,
                $block
            )
            && $this->callArgOperandIsClosureValue($arg, $block, $calleeName)
            && !$this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) instanceof Op\Expr\Array_
            && $this->inlineClosureArrayPairCallbackArgIndex($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp)) === (int) $argIndex
        ) {
            $closureSlot = $this->slotForRecentClosureCallArg($block);
            if (null !== $closureSlot) {
                $valueSlot = $closureSlot;
            }
        }
        if (
            null === $valueSlot
            && null !== $cfgCallOp
            && $this->callArgIsNullLiteral(
                $cfgCallOp->args[(int) $argIndex] ?? $arg,
                $cfgCallOp,
                (int) $argIndex,
                $block
            )
        ) {
            $nullArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if ($nullArg instanceof Operand) {
                $valueSlot = $this->registerNullConstantSlot($block, $nullArg);
            }
        }
        if (null !== $cfgCallOp) {
            $pointerSlot = $this->slotForHoistedArrayPointerBuiltinCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $arg
            );
            if (null !== $pointerSlot) {
                $valueSlot = $pointerSlot;
            }
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $trailingComparisonProducer = null;
            $cfgCallIndex = null;
            foreach ($block->orig->children as $ci => $cfgChild) {
                if ($cfgChild === $cfgCallOp) {
                    $cfgCallIndex = $ci;
                    break;
                }
            }
            if (null !== $cfgCallIndex && $cfgCallIndex > 0) {
                $immediatePrelude = $block->orig->children[$cfgCallIndex - 1] ?? null;
                if ($this->isComparisonInlineCallArgProducer($immediatePrelude)) {
                    $trailingComparisonProducer = $immediatePrelude;
                }
            }
            if (null === $trailingComparisonProducer) {
                $trailingProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                foreach (array_reverse($trailingProducers) as $producer) {
                    if ($this->isComparisonInlineCallArgProducer($producer)) {
                        $trailingComparisonProducer = $producer;
                        break;
                    }
                }
            }
            $comparisonFeedsCallArg = $this->isComparisonInlineCallArgProducer($trailingComparisonProducer);
            if (
                0 === (int) $argIndex
                && $comparisonFeedsCallArg
                && $this->callArgIsDeadInlineTemporary($arg)
                && $trailingComparisonProducer instanceof Op\Expr
                && null !== $trailingComparisonProducer->result
            ) {
                $comparisonSlot = $block->slotForOperand($trailingComparisonProducer->result);
                if (null === $comparisonSlot) {
                    foreach ($this->compileExpr($trailingComparisonProducer, $block) as $op) {
                        $sends[] = $op;
                    }
                    $comparisonSlot = $block->slotForOperand($trailingComparisonProducer->result);
                }
                if (null !== $comparisonSlot) {
                    $valueSlot = (string) $comparisonSlot;
                }
            }
            if (
                0 === $argIndex
                && !$comparisonFeedsCallArg
                && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[0] ?? $arg)
                && !$this->callArgOperandExpectsArrayProducer($cfgCallOp->args[0] ?? $arg)
                && !$this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
            ) {
                $concatChainOps = [];
                $chainedConcatSlot = $this->tryResolveChainedConcatCallArgSlot(
                    $arg,
                    $block,
                    $concatChainOps,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $chainedConcatSlot) {
                    if ([] !== $concatChainOps) {
                        $sends = array_merge($sends, $concatChainOps);
                    }
                    $valueSlot = (string) $chainedConcatSlot;
                } else {
                    $arithmeticChainOps = [];
                    $chainedArithmeticSlot = $this->tryResolveChainedArithmeticCallArgSlot(
                        $arg,
                        $block,
                        $arithmeticChainOps,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $chainedArithmeticSlot) {
                        if ([] !== $arithmeticChainOps) {
                            $sends = array_merge($sends, $arithmeticChainOps);
                        }
                        $valueSlot = (string) $chainedArithmeticSlot;
                    }
                }
                if (null === $valueSlot) {
                    $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    $trailingProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    foreach ($trailingProducers as $producer) {
                        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                            continue;
                        }
                        if (
                            null === $callArgProbe
                            || !$this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArgProbe)
                        ) {
                            continue;
                        }
                        $subjectSlot = $block->slotForOperand($producer->result);
                        if (null !== $subjectSlot) {
                            $valueSlot = (string) $subjectSlot;
                            break;
                        }
                    }
                }
            }
            if (null !== $cfgCallOp && 0 === (int) $argIndex) {
                $varExportOps = [];
                $varExportSlot = $this->slotForVarExportNestedInlineCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $varExportOps
                );
                if (null !== $varExportSlot && !$inlineArrayLiteralArgWired) {
                    if ([] !== $varExportOps) {
                        $sends = array_merge($sends, $varExportOps);
                    }
                    $valueSlot = (string) $varExportSlot;
                }
            }
            if (
                $this->callArgIsDeadInlineTemporary($arg)
                && !$comparisonFeedsCallArg
                && !$this->callArgOperandExpectsArrayProducer(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg
                )
                && !(
                    null !== $cfgCallOp
                    && 'var_export' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && 0 === (int) $argIndex
                )
                && !(
                    null !== $cfgCallOp
                    && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                )
            ) {
            $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            foreach ($trailingProducers as $producer) {
                if (!$producer instanceof Op\Expr\ConstFetch && !$producer instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                if (!$this->operandsReferToSameVariable($producer->result, $callArgProbe)) {
                    continue;
                }
                if (null === $block->slotForOperand($producer->result)) {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $sends[] = $op;
                    }
                }
                $constSlot = $block->slotForOperand($producer->result);
                if (null !== $constSlot) {
                    $valueSlot = (string) $constSlot;
                    break;
                }
            }
            }
        }
    }
}
