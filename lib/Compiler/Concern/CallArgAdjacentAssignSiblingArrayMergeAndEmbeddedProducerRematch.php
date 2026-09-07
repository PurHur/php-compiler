<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Adjacent-assign emit + sibling array-producer skip / embedded-literal /
 * array_merge leading rematch (#36387 / #36403). Runs after multi-producer /
 * array_column / search / pad rematch.
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$inlineArrayLiteralArgWired` / `$sends` by-ref.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgAdjacentAssignSiblingArrayMergeAndEmbeddedProducerRematch
{
    /**
     * @param list<OpCode> $sends
     * @param-out mixed $valueSlot
     * @param-out bool $inlineArrayLiteralArgWired
     */
    private function resolveCallArgAdjacentAssignSiblingArrayMergeAndEmbeddedProducerRematch(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $unpackFlag,
        mixed $dimFetchSlot,
        array &$sends,
        &$valueSlot,
        &$inlineArrayLiteralArgWired
    ): void {
        foreach ($this->tryEmitAdjacentAssignForInlineCallArg(
            $arg,
            null !== $valueSlot ? (string) $valueSlot : null,
            $block,
            $cfgCallOp,
            (int) $argIndex
        ) as $assignOp) {
            $sends[] = $assignOp;
        }
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
    }
}
