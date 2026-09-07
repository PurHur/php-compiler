<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-dead-array early valueSlot wiring (#36387 / #36403): filter_var / is_a /
 * is_subclass_of nested-subject rematch, json_decode producer rematch,
 * array_merge trailing inline array, array_map / array_filter callback+haystack,
 * and preg_split / explode unary/ConstFetch folds.
 *
 * Extracted from {@see CompileCallArgSends} after
 * {@see CallArgDeadArrayComparisonConcatPointerValueSlots} so gen-0
 * split-TU can hollow a smaller Concern TU. Mutates `$valueSlot` / `$sends` by-ref.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgFilterJsonDecodeMergeMapFilterSplitExplodeValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     */
    private function resolveCallArgFilterJsonDecodeMergeMapFilterSplitExplodeValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array &$sends,
        &$valueSlot
    ): void {
        if (
            null !== $cfgCallOp
            && 0 === $argIndex
            && $this->callArgIsDeadInlineTemporary($arg)
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['filter_var', 'is_a', 'is_subclass_of'],
                true
            )
        ) {
            $nestedSubjectSlot = $this->slotForNestedSubjectExecBeforeLiteralPreludeCall($block);
            if (null !== $nestedSubjectSlot) {
                $valueSlot = $nestedSubjectSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'json_decode' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
        ) {
            if (0 === (int) $argIndex) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                foreach ($producers as $producer) {
                    if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                        continue;
                    }
                    $subjectSlot = $block->slotForOperand($producer->result);
                    if (null === $subjectSlot) {
                        $nestedSubjectSlot = $this->slotForNestedSubjectExecBeforeLiteralPreludeCall($block);
                        if (null !== $nestedSubjectSlot) {
                            $valueSlot = $nestedSubjectSlot;
                        }
                        break;
                    }
                    $valueSlot = (string) $subjectSlot;
                    break;
                }
            } elseif (1 === (int) $argIndex || 3 === (int) $argIndex) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $target = $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                    $producers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    'json_decode'
                );
                if ($target instanceof Op\Expr) {
                    $folded = $target instanceof Op\Expr\ConstFetch
                        ? $this->tryFoldGlobalConstFetch($target)
                        : null;
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
                }
            }
        }
        if (
            null !== $cfgCallOp
            && (int) $argIndex > 0
            && null !== $calleeName
            && \in_array(strtolower($calleeName), ['array_merge', 'array_merge_recursive'], true)
        ) {
            $mergeTrailingSlot = $this->resolveArrayMergeTrailingInlineArrayCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $arg,
                $sends
            );
            if (null !== $mergeTrailingSlot) {
                $valueSlot = $mergeTrailingSlot;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'array_map' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            if (0 === (int) $argIndex) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $producer = $this->matchInlineCallArgProducer(
                    $producers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    'array_map'
                );
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $slot = $block->slotForOperand($producer->result);
                    if (null === $slot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                }
            } elseif ((int) $argIndex >= 1) {
                $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if (null !== $callArgProbe && $this->callArgOperandExpectsArrayProducer($callArgProbe)) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $producer = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        'array_map'
                    );
                    if (!$producer instanceof Op\Expr\Array_) {
                        $producer = $this->matchInlineArrayProducersToArrayCallArgs(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex
                        );
                    }
                    if (!$producer instanceof Op\Expr\Array_) {
                        $haystackProducer = $this->leadingCallbackFirstHaystackFuncCallBeforeCfgCall($cfgCallOp, $block);
                        if ($haystackProducer instanceof Op\Expr\FuncCall
                            || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                            $producer = $haystackProducer;
                        }
                    }
                    if ($producer instanceof Op\Expr\Array_) {
                        $slot = $block->slotForOperand($producer->result);
                        if (null === $slot) {
                            foreach ($this->compileArrayLiteral($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($producer->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                        $slot = $block->slotForOperand($producer->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($producer->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    }
                }
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'array_filter' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            if (0 === (int) $argIndex) {
                $haystackProducer = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
                if ($haystackProducer instanceof Op\Expr\FuncCall
                    || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                    $slot = $block->slotForOperand($haystackProducer->result);
                    if (null === $slot) {
                        foreach ($this->compileExpr($haystackProducer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($haystackProducer->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                }
            } elseif (1 === (int) $argIndex) {
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
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'preg_split' === $this->resolveCfgFuncCallName($cfgCallOp)
            && ((int) $argIndex === 2 || (int) $argIndex === 3)
        ) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $unaryProducer = null;
            $constProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                }
            }
            $targetProducer = 2 === (int) $argIndex ? $unaryProducer : $constProducer;
            if ($targetProducer instanceof Op\Expr) {
                $folded = null;
                if ($targetProducer instanceof Op\Expr\ConstFetch) {
                    $folded = $this->tryFoldGlobalConstFetch($targetProducer);
                } elseif (
                    $targetProducer instanceof Op\Expr\UnaryMinus
                    || $targetProducer instanceof Op\Expr\UnaryPlus
                ) {
                    $folded = $this->tryFoldUnaryLiteralDefault($targetProducer);
                }
                if (null !== $folded) {
                    $valueSlot = (string) $block->registerConstant(
                        new Operand\Temporary(),
                        $folded
                    );
                } else {
                    $slot = $block->slotForOperand($targetProducer->result);
                    if (null === $slot) {
                        foreach ($this->compileExpr($targetProducer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($targetProducer->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                }
            }
        } elseif (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'explode' === $this->resolveCfgFuncCallName($cfgCallOp)
            && 2 === (int) $argIndex
        ) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            foreach ($producers as $producer) {
                if (!$producer instanceof Op\Expr\UnaryMinus && !$producer instanceof Op\Expr\UnaryPlus) {
                    continue;
                }
                $folded = $this->tryFoldUnaryLiteralDefault($producer);
                if (null !== $folded) {
                    $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
                    break;
                }
                $slot = $block->slotForOperand($producer->result);
                if (null === $slot) {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $sends[] = $op;
                    }
                    $slot = $block->slotForOperand($producer->result);
                }
                if (null !== $slot) {
                    $valueSlot = (string) $slot;
                }
                break;
            }
        }
    }
}
