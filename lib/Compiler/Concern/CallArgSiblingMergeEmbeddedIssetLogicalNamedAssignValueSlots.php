<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-MultiProducer valueSlot recovery (#36387 / #36403): sibling inline producers,
 * array_merge-family leading-arg skip/remap, embedded-literal producer match,
 * haystack-family array producer, hoisted isset/empty, logical short-circuit PHI,
 * leading-coalesce clobber recovery, and named-assign dest finalize.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgMultiProducerNamedLocalArrayColumnSearchPadValueSlots} so gen-0
 * split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends` /
 * `$namedAssignDest` / `$inlineArrayLiteralArgWired` by-ref. Mirrors php-src Zend/zend_compile.c
 * call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgSiblingMergeEmbeddedIssetLogicalNamedAssignValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $namedAssignDest
     * @param-out bool $inlineArrayLiteralArgWired
     */
    private function resolveCallArgSiblingMergeEmbeddedIssetLogicalNamedAssignValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $unpackFlag,
        mixed $dimFetchSlot,
        mixed $nameSlot,
        array &$sends,
        &$valueSlot,
        &$namedAssignDest,
        bool &$inlineArrayLiteralArgWired
    ): void {
        if (null !== $cfgCallOp) {
            $skipSiblingArrayProducer = (null !== $unpackFlag)
                || (
                    $this->callArgIsDeadInlineTemporary($arg)
                    && $this->callArgOperandExpectsArrayProducer($arg)
                    && !$this->shouldUseArrayProducerCallArgResolution($cfgCallOp, (int) $argIndex, $calleeName)
                );
            $skipSiblingForLeadingArrayMergeFamily = false;
            if (null !== $block->orig) {
                $mergeName = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                if (
                    \in_array($mergeName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                ) {
                    $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if (null !== $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                        $mergeProducers,
                        (int) $argIndex
                    )) {
                        $skipSiblingForLeadingArrayMergeFamily = true;
                    } elseif (0 === (int) $argIndex && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)) {
                        $skipSiblingForLeadingArrayMergeFamily = true;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgIsDeadInlineTemporary($arg)
                && !$inlineArrayLiteralArgWired
                && null === $dimFetchSlot
                && null === $valueSlot
            ) {
                $embeddedProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $embeddedTarget = $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                    $embeddedProducers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
                if ($embeddedTarget instanceof Op\Expr) {
                    $foldedEmbedded = $embeddedTarget instanceof Op\Expr\ConstFetch
                        ? $this->tryFoldGlobalConstFetch($embeddedTarget)
                        : null;
                    if (null !== $foldedEmbedded) {
                        $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $foldedEmbedded);
                    } else {
                        $embeddedSlot = $block->slotForOperand($embeddedTarget->result);
                        if (null === $embeddedSlot) {
                            foreach ($this->compileExpr($embeddedTarget, $block) as $op) {
                                $sends[] = $op;
                            }
                            $embeddedSlot = $block->slotForOperand($embeddedTarget->result);
                        }
                        if (null !== $embeddedSlot) {
                            $valueSlot = (string) $embeddedSlot;
                        }
                    }
                }
            }
            if (!$skipSiblingArrayProducer && !$skipSiblingForLeadingArrayMergeFamily && null === $valueSlot) {
            $siblingOps = [];
            $siblingSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $siblingOps
            );
            if (null !== $siblingSlot && !$inlineArrayLiteralArgWired && null === $dimFetchSlot) {
                if ([] !== $siblingOps) {
                    $sends = array_merge($sends, $siblingOps);
                }
                $valueSlot = $siblingSlot;
            } elseif (
                null !== $cfgCallOp
                && $this->siblingConsumerHasTrailingByRefNamedLocal($cfgCallOp)
                && $this->callArgIsDeadInlineTemporary($arg)
            ) {
                // #15476 regression from #15848: operand→slot map drifts for precompiled
                // hoisted str_repeat() producers when a trailing by-ref local follows.
                $execReturnSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                    $block,
                    (int) $argIndex
                );
                if (null !== $execReturnSlot) {
                    $valueSlot = (string) $execReturnSlot;
                }
            }
            }
            if (
                0 === (int) $argIndex
                && $skipSiblingForLeadingArrayMergeFamily
                && null === $unpackFlag
                && null === $valueSlot
                && null !== $block->orig
            ) {
                $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                    $block->orig->children,
                    $cfgCallOp
                );
                $mergeMapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                    $mergeProducers,
                    (int) $argIndex
                );
                if (
                    $mergeMapped instanceof Op\Expr\FuncCall
                    || $mergeMapped instanceof Op\Expr\NsFuncCall
                ) {
                    $funcOrdinal = 0;
                    foreach ($mergeProducers as $producer) {
                        if ($producer === $mergeMapped) {
                            break;
                        }
                        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                            ++$funcOrdinal;
                        }
                    }
                    $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
                    if (null !== $execSlot) {
                        $valueSlot = (string) $execSlot;
                    }
                } elseif ($mergeMapped instanceof Op\Expr\Array_) {
                    $leadingInitSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                    if (null !== $leadingInitSlot) {
                        $valueSlot = $leadingInitSlot;
                        $inlineArrayLiteralArgWired = true;
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $nameSlot
            && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
            && null !== $block->orig
        ) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
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
                $arraySlot = $block->slotForOperand($matched->result);
                if (null !== $arraySlot) {
                    $valueSlot = $arraySlot;
                }
            }
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $recoveredIssetEmpty = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $recoveredIssetEmpty) {
                $valueSlot = $recoveredIssetEmpty;
            }
        }
        if (
            null !== $cfgCallOp
            && $this->callArgIsDeadInlineTemporary($arg)
            && null !== $block->orig
            && !$this->callArgOperandExpectsArrayProducer($arg)
        ) {
            $logicalPhi = $this->logicalShortCircuitOrPhiMergeSlot($block);
            if (null !== $logicalPhi) {
                $valueSlot = (string) $logicalPhi;
            } else {
                $andPhi = $this->logicalShortCircuitPhiMergeSlot($block);
                if (null !== $andPhi) {
                    $valueSlot = (string) $andPhi;
                } elseif (\in_array(strtolower($calleeName ?? ''), ['exit', 'die'], true)) {
                    $exitPhi = $this->resolveExitLogicalShortCircuitCallArgSlot($block);
                    if (null !== $exitPhi) {
                        $valueSlot = $exitPhi;
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && $argIndex > 0
            && null !== $valueSlot
            && is_array($cfgCallOp->args ?? null)
            && isset($cfgCallOp->args[0])
        ) {
            $leadingCoalesce = $this->findCoalesceStmtForCallArg($cfgCallOp->args[0], $block);
            if (null !== $leadingCoalesce) {
                $coalesceSlot = $this->slotForCoalesceResult($block, $leadingCoalesce);
                if (null !== $coalesceSlot && (string) $valueSlot === (string) $coalesceSlot) {
                    $hoisted = $this->tryFoldHoistedBoolNullLiteralCallArg(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $hoisted) {
                        $valueSlot = $hoisted;
                    } elseif ($this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
                        $direct = $this->compileOperand($arg, $block, true);
                        if (null !== $direct) {
                            $valueSlot = $direct;
                        }
                    }
                }
            }
        }
        $namedAssignDestProbe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $namedAssignDestProbe = $cfgCallOp->args[(int) $argIndex];
        }
        $namedAssignDest = $block->slotForNamedAssignDest($namedAssignDestProbe);
        if (null !== $namedAssignDest) {
            $valueSlot = $this->resolveNamedAssignCallArgSlot(
                $block,
                (int) $namedAssignDest,
                $calleeName,
                (int) $argIndex,
                $namedAssignDestProbe
            );
        } elseif (null !== $valueSlot && is_numeric($valueSlot)) {
            $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, (int) $valueSlot, true);
        }
    }
}
