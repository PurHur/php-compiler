<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Residual valueSlot resolvers after dim/coalesce/New_/fold/adjacent early slots
 * (#36387 / #36403): inline/preceding closure, Closure::bind/fromCallable scan,
 * dead-temp unary/fseek/class-const/array-func producers, NullOperand register,
 * prefer-producer-over-named-local, preceding eval, multi-Array_ prefer skip,
 * and preferNamedLocalCallArgSlot.
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$assignedNamedLocal` / `$sends` by-ref when
 * `!$tookDimOrInlineArrayBranch`. Mirrors php-src Zend/zend_compile.c call-arg
 * send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgClosureDeadTempNullPreferProducerEvalAndNamedLocalValueSlots
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $assignedNamedLocal
     */
    private function resolveCallArgClosureDeadTempNullPreferProducerEvalAndNamedLocalValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        bool $hoistedEnumPropertyCallArgSlotWired,
        bool $inlineArrayLiteralArgWired,
        array &$sends,
        &$valueSlot,
        &$assignedNamedLocal
    ): void {
        $closureSlot = $this->resolveInlineClosureCallArgSlot($arg, $block, $cfgCallOp, $calleeName);
        $precedingClosureSlot = null;
        if (null === $closureSlot && null !== $cfgCallOp) {
            $precedingClosureSlot = $this->resolvePrecedingClosureCallArgSlot(
                $cfgCallOp,
                (int) $argIndex,
                $block,
                $calleeName
            );
            if (null !== $precedingClosureSlot) {
                $closureSlot = $precedingClosureSlot;
            }
        }
        if (
            null !== $closureSlot
            && null === $assignedNamedLocal
            && !$this->isNamedVariableOperand($arg)
            && !$this->callArgIsNullLiteral(
                $cfgCallOp->args[(int) $argIndex] ?? $arg,
                $cfgCallOp,
                (int) $argIndex,
                $block
            )
            && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && (
                null !== $precedingClosureSlot
                || $this->callArgOperandIsClosureValue($arg, $block, $calleeName)
            )
            && !(
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
                && $this->callArgOperandExpectsArrayProducer($cfgCallOp->args[(int) $argIndex] ?? $arg)
            )
        ) {
            $valueSlot = $closureSlot;
        }
        if (
            null === $valueSlot
            && 0 === $argIndex
            && null !== $calleeName
            && ('Closure::bind' === $calleeName || 'Closure::fromCallable' === $calleeName)
        ) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $scanOp = $block->opCodes[$i];
                if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                    break;
                }
                if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                    $valueSlot = $scanOp->arg1;
                    break;
                }
                if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                    $valueSlot = $scanOp->arg1;
                    break;
                }
            }
        }
        if (null === $valueSlot) {
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgIsDeadInlineTemporary($arg)
            ) {
                $immediateUnarySlot = $this->slotForImmediateUnaryHoistedCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName
                );
                if (null !== $immediateUnarySlot) {
                    $valueSlot = $immediateUnarySlot;
                }
                if (null === $valueSlot) {
                    $fseekWhenceSlot = $this->slotForFseekWhenceHoistedCallArg(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $calleeName
                    );
                    if (null !== $fseekWhenceSlot) {
                        $valueSlot = $fseekWhenceSlot;
                    }
                }
            }
            if (
                null === $valueSlot
                && null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgIsDeadInlineTemporary($arg)
                && !(
                    $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                    && !$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, (int) $argIndex)
                )
            ) {
                $valueSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                if (null === $valueSlot) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $matched = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        $calleeName
                    );
                    if ($matched instanceof Op\Expr) {
                        if (null === $block->slotForOperand($matched->result)) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $matchedSlot = $block->slotForOperand($matched->result);
                        if (null !== $matchedSlot) {
                            $valueSlot = $matchedSlot;
                        }
                    }
                }
            }
            if (null === $valueSlot) {
                if (null !== $cfgCallOp && $this->callArgIsDeadInlineTemporary($arg)) {
                    $classConstSlot = $this->slotForHoistedClassConstFetchCallArg(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $classConstSlot) {
                        $valueSlot = $classConstSlot;
                    }
                }
                if (null === $valueSlot) {
                    if (
                        null !== $cfgCallOp
                        && null !== $block->orig
                        && $this->callArgIsDeadInlineTemporary($arg)
                        && $this->callArgOperandExpectsArrayProducer($arg)
                    ) {
                        $arrayFuncSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null === $arrayFuncSlot) {
                            $arrayProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $arrayMatched = $this->matchInlineCallArgProducer(
                                $arrayProducers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex,
                                $cfgCallOp,
                                $block,
                                $calleeName
                            );
                            if (
                                $arrayMatched instanceof Op\Expr\FuncCall
                                || $arrayMatched instanceof Op\Expr\NsFuncCall
                            ) {
                                $arrayFuncSlot = $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $arrayMatched,
                                    $cfgCallOp,
                                    $block->orig->children
                                );
                            }
                        }
                        if (null !== $arrayFuncSlot) {
                            $valueSlot = $arrayFuncSlot;
                        }
                    }
                    if (null === $valueSlot) {
                        $valueSlot = $this->compileOperand($arg, $block, true);
                    }
                }
            }
        }
        if (null === $valueSlot && $arg instanceof Operand\NullOperand) {
            $valueSlot = $this->registerNullConstantSlot($block, $arg);
        }
        if (
            null === $assignedNamedLocal
            && null !== $valueSlot
            && !$hoistedEnumPropertyCallArgSlotWired
            && !$inlineArrayLiteralArgWired
            && !$this->isCallArgDirectArrayDimFetch($arg)
            && null !== $block->orig
            && ($arg instanceof Operand\Variable || $arg instanceof Operand\Temporary)
            && !(
                null !== $cfgCallOp
                && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
            )
        ) {
            $hasProducer = false;
            foreach ($block->orig->children as $child) {
                if (!($child instanceof Op\Expr) || null === $child->result) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($child->result, $arg)) {
                    $hasProducer = true;
                    break;
                }
            }
            if (!$this->callArgHasPriorStmtCoalesce($arg, $block, $cfgCallOp, (int) $argIndex)) {
                $immediateUnarySlot = null;
                if (null !== $cfgCallOp) {
                    $immediateUnarySlot = $this->slotForImmediateUnaryHoistedCallArg(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $calleeName
                    );
                }
                if (null !== $immediateUnarySlot) {
                    $valueSlot = $immediateUnarySlot;
                } else {
                $producerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                if (
                    null !== $producerSlot
                    && !$this->isNamedVariableOperand($arg)
                    && null === $this->namedLocalCallArgSlotIfBound($arg, $block, $cfgCallOp, (int) $argIndex)
                ) {
                    $valueSlot = $producerSlot;
                } elseif (null !== $cfgCallOp) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $matched = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        $calleeName
                    );
                    if ($matched instanceof Op\Expr) {
                        $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        if (
                            ($matched instanceof Op\Expr\MethodCall
                                || $matched instanceof Op\Expr\FuncCall
                                || $matched instanceof Op\Expr\NsFuncCall
                                || $matched instanceof Op\Expr\StaticCall)
                            && $callArgProbe instanceof Operand
                            && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                        ) {
                            $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                            if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                                $matched = $stmtBeforeArray;
                            }
                        }
                        $matchedSlot = $this->slotForInlineCallArgProducerResult(
                            $block,
                            $matched,
                            $cfgCallOp,
                            $block->orig->children
                        ) ?? $block->slotForOperand($matched->result);
                        if (null === $matchedSlot) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $block->addOpCode($op);
                            }
                            $matchedSlot = $this->slotForInlineCallArgProducerResult(
                                $block,
                                $matched,
                                $cfgCallOp,
                                $block->orig->children
                            ) ?? $block->slotForOperand($matched->result);
                        }
                        if (null !== $matchedSlot) {
                            $valueSlot = $matchedSlot;
                        }
                    }
                }
                }
            }
        }
        $evalSlot = $this->resolvePrecedingEvalCallArgSlot(
            $arg,
            $block,
            $cfgCallOp,
            (int) $argIndex
        );
        if (null !== $evalSlot) {
            $valueSlot = $evalSlot;
        }
        $skipPreferNamedLocal = false;
        if (null !== $cfgCallOp && null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $arrayProducerCount = 0;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    ++$arrayProducerCount;
                }
            }
            if (
                $arrayProducerCount >= 2
                && !$this->callIncludesNamedParameter($cfgCallOp)
                && null === $this->slotForNamedLocalFromAssignVarOperand($arg, $block)
            ) {
                $matched = $this->matchInlineCallArgProducer(
                    $producers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
                if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
                    $matchedSlot = $this->slotForInlineCallArgProducerResult(
                        $block,
                        $matched,
                        $cfgCallOp,
                        null !== $block->orig ? $block->orig->children : null
                    );
                    if (null === $matchedSlot) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                        $matchedSlot = $this->slotForInlineCallArgProducerResult(
                            $block,
                            $matched,
                            $cfgCallOp,
                            null !== $block->orig ? $block->orig->children : null
                        );
                    }
                    if (null !== $matchedSlot) {
                        $valueSlot = $matchedSlot;
                        $skipPreferNamedLocal = true;
                    }
                }
            }
        }
        if (!$skipPreferNamedLocal) {
            $valueSlot = $this->preferNamedLocalCallArgSlot(
                $arg,
                $block,
                $valueSlot,
                (
                    null !== $cfgCallOp
                    && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                ) ? null : $calleeName
            );
        }
    }
}
