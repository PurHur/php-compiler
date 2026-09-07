<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Nested var_export() ARG_SEND rewire for dead-temp / dim-fetch / MethodCall /
 * FuncCall producers (#36387 / #36403).
 *
 * Extracted from {@see RewireHoistedPreludePregCombineAndVarExportCallArgSendSlots}
 * so gen-0 split-TU can hollow a smaller Concern TU. Complements Bitmask /
 * comparison-return-flag peers left in sibling traits.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait RewireVarExportNestedInlineCallArgSendSlots
{
    /**
     * var_export(array_keys([null => 1], null), true) — arg #0 must use nested FUNCCALL_EXEC_RETURN, not INIT_ARRAY (#16107).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireVarExportNestedInlineCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('var_export' !== $callee || null === $cfgCallOp) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) instanceof Op\Expr\Array_
        ) {
            return;
        }
        if ($this->callArgIsNullLiteral($callArg, $cfgCallOp, 0, $block)) {
            return;
        }
        $pendingDimFetchSlot = $this->lastPendingCallArgArrayDimFetchSlot(
            $block,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        if (null !== $pendingDimFetchSlot) {
            // var_export(empty($a['x']['y'])) / isset(...) — quiet dim-fetch prelude must not steal arg #0 (#21991).
            if (null !== $block->orig) {
                $issetEmptyProducer = $this->findHoistedIssetOrEmptyProducerForCallArg($block, $cfgCallOp, 0);
                if (null !== $issetEmptyProducer) {
                    return;
                }
                // var_export($u[0] === $u[1]) — Identical feeds arg #0, not trailing ArrayDimFetch rhs (#12082).
                $comparisonProducer = $this->matchBooleanBinaryOpInlineCallArgProducer(
                    $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    ),
                    $callArg
                );
                if (null !== $comparisonProducer) {
                    return;
                }
                // var_export((string)$xml['a']) — Cast feeds arg #0; dim-fetch is the Cast operand (#25339).
                // Skip trailing true/false return-flag ConstFetch so two-arg form still sees the Cast.
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $probeIndex = $callIndex - 1;
                    while ($probeIndex >= 0) {
                        $probe = $block->orig->children[$probeIndex] ?? null;
                        if ($probe instanceof Op\Expr\ConstFetch) {
                            $flagName = strtolower($this->staticNameFromOperand($probe->name) ?? '');
                            if (\in_array($flagName, ['true', 'false'], true)) {
                                --$probeIndex;
                                continue;
                            }
                        }
                        break;
                    }
                    $argPrelude = $block->orig->children[$probeIndex] ?? null;
                    if ($argPrelude instanceof Op\Expr\Cast) {
                        return;
                    }
                    // var_export($arr['o']->name, true) — PropertyFetch feeds arg #0;
                    // ArrayDimFetch is the object receiver (#31938, zend_execute.c FETCH_OBJ_R).
                    if (
                        $argPrelude instanceof Op\Expr\PropertyFetch
                        || $argPrelude instanceof Op\Expr\NullsafePropertyFetch
                        || $argPrelude instanceof Op\Expr\StaticPropertyFetch
                    ) {
                        return;
                    }
                }
            }
            $trueSlot = null;
            foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps, $outerArgSends)) as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    break;
                }
                if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg2) {
                    continue;
                }
                $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
                if ('true' === strtolower($name ?? '')) {
                    $trueSlot = $op->arg1;
                    break;
                }
            }
            $sendOrdinal = 0;
            foreach ($outerArgSends as &$send) {
                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                    continue;
                }
                if (0 === $sendOrdinal) {
                    $send->arg1 = (string) $pendingDimFetchSlot;
                } elseif (1 === $sendOrdinal && null !== $trueSlot) {
                    $send->arg1 = (string) $trueSlot;
                }
                ++$sendOrdinal;
            }
            unset($send);

            return;
        }
        if ($this->isCallArgDirectArrayDimFetch($callArg)) {
            $dimSlot = $this->lastPendingCallArgArrayDimFetchSlot($block, $nestedProducerOps);
            if (null !== $dimSlot) {
                $sendOrdinal = 0;
                foreach ($outerArgSends as &$send) {
                    if (OpCode::TYPE_ARG_SEND !== $send->type) {
                        continue;
                    }
                    if (0 === $sendOrdinal) {
                        $send->arg1 = (string) $dimSlot;
                    }
                    ++$sendOrdinal;
                }
                unset($send);
            }

            return;
        }
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $stmtBefore = $block->orig->children[$callIndex - 1] ?? null;
                $hoistedNullFeedsSoleArg = $stmtBefore instanceof Op\Expr\ConstFetch
                    && $this->constFetchIsNull($stmtBefore)
                    && \is_array($cfgCallOp->args ?? null)
                    && 1 === \count($cfgCallOp->args)
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null);
                if (
                    ($stmtBefore instanceof Op\Expr\ConstFetch || $stmtBefore instanceof Op\Expr\ClassConstFetch)
                    && (
                        null === $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes(
                            $callIndex,
                            $block->orig->children
                        )
                        || $hoistedNullFeedsSoleArg
                    )
                ) {
                    // var_export($expr, true|false) — hoisted return flag is not arg #0 (#17895, #17251).
                    $skipConstEarlyReturn = false;
                    if (
                        \is_array($cfgCallOp->args ?? null)
                        && \count($cfgCallOp->args) >= 2
                        && $stmtBefore instanceof Op\Expr\ConstFetch
                    ) {
                        $name = $this->staticNameFromOperand($stmtBefore->name);
                        if (\in_array(strtolower($name ?? ''), ['true', 'false'], true)) {
                            $skipConstEarlyReturn = true;
                        }
                    }
                    if (!$skipConstEarlyReturn) {
                        $constSlot = $block->slotForOperand($stmtBefore->result);
                        if (null === $constSlot) {
                            foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps)) as $op) {
                                if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg1) {
                                    continue;
                                }
                                $constSlot = $op->arg1;
                                break;
                            }
                        }
                        if (null !== $constSlot) {
                            foreach ($outerArgSends as &$send) {
                                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                                    continue;
                                }
                                $send->arg1 = (string) $constSlot;
                                break;
                            }
                            unset($send);

                            return;
                        }
                    }
                }
            }
        }
        $execSlot = null;
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($callIndex) && $callIndex > 0) {
                $probeIndex = $callIndex - 1;
                while ($probeIndex >= 0) {
                    $probe = $block->orig->children[$probeIndex] ?? null;
                    if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                        --$probeIndex;
                        continue;
                    }
                    break;
                }
                $producer = $block->orig->children[$probeIndex] ?? null;
                // var_export($named === $pos) — comparison feeds arg #0, not prior fgets EXEC_RETURN (#11052, #17277).
                if ($this->isComparisonInlineCallArgProducer($producer)) {
                    return;
                }
                // var_export(isset($obj->p), true) / empty(...) — bool from TYPE_ISSET/EMPTY, not stale EXEC_RETURN (#17555).
                if ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) {
                    return;
                }
                // var_export(require_once $f, true) — Include_/Eval_ result, not prior getmypid EXEC_RETURN (#25852).
                if ($producer instanceof Op\Expr\Include_ || $producer instanceof Op\Expr\Eval_) {
                    return;
                }
                // var_export($text->data) / var_export(JSON_HEX_TAG | JSON_HEX_AMP) — expression prelude feeds arg #0, not stale FuncCall EXEC_RETURN (#17540, #17562).
                $producerExpr = $producer instanceof Op\Expr\Assign ? $producer->expr : $producer;
                if ($this->isImmediateVarExportExpressionPrelude($producerExpr)) {
                    return;
                }
                // var_export("{$c}") / var_export("a{$c}b") — ConcatList already lowered via
                // tryResolveEncapsedConcatListCallArgSlot; do not steal prior New_ EXEC_RETURN (#26489 / #13466).
                // Keep this check out of isImmediateVarExportExpressionPrelude: that helper's other
                // callers compileExpr() the prelude, and ConcatList is not an Expr compile path.
                if (
                    $producerExpr instanceof Op\Expr\ConcatList
                    || $producerExpr instanceof Op\Expr\BinaryOp\Concat
                ) {
                    return;
                }
                if ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall) {
                    if (null === $block->slotForOperand($producer->result)) {
                        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                        $this->forceDeferredSiblingCallReturnSlot = true;
                        try {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $block->addOpCode($op);
                            }
                        } finally {
                            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                        }
                    }
                    $pairedExec = $this->slotForMethodOrStaticCallInitFollowingExecReturn(
                        $block,
                        $producer,
                        $nestedProducerOps
                    );
                    if (null !== $pairedExec) {
                        $execSlot = $pairedExec;
                    } else {
                        $operandSlot = $block->slotForOperand($producer->result);
                        if (null !== $operandSlot) {
                            $execSlot = (string) $operandSlot;
                        } else {
                            $execSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                                $block,
                                $producer,
                                $cfgCallOp,
                                $block->orig->children
                            );
                        }
                    }
                } elseif ($producer instanceof Op\Expr\ArrayDimFetch) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer($producers, 0);
                    if ($chainedDimFetch instanceof Op\Expr\ArrayDimFetch && null !== $chainedDimFetch->result) {
                        $dimSlot = $block->slotForOperand($chainedDimFetch->result);
                        if (null === $dimSlot) {
                            $dimFetches = array_values(array_filter(
                                $producers,
                                static fn (Op\Expr $p): bool => $p instanceof Op\Expr\ArrayDimFetch
                            ));
                            if (
                                \count($dimFetches) >= 2
                                && $this->arrayDimFetchesFormProducerChain($dimFetches)
                            ) {
                                $dimSlot = $this->pendingCallArgArrayDimFetchSlot(
                                    $block,
                                    array_merge($block->opCodes, $nestedProducerOps),
                                    \count($dimFetches) - 1
                                );
                            }
                        }
                        if (null !== $dimSlot) {
                            $execSlot = (string) $dimSlot;
                        }
                    }
                } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $operandSlot = $block->slotForOperand($producer->result);
                    if (null !== $operandSlot) {
                        $execSlot = (string) $operandSlot;
                    } else {
                        $execSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $probeIndex,
                            $block->orig->children
                        );
                    }
                }
            }
        }
        if (null === $execSlot) {
            $execSlot = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($nestedProducerOps);
        }
        if (null === $execSlot) {
            $execSlot = $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
        }
        if (null === $execSlot) {
            return;
        }
        $initSlots = [];
        foreach (array_merge($block->opCodes, $nestedProducerOps) as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initSlots[] = $op->arg1;
            }
        }
        $trueSlot = null;
        foreach (array_reverse(array_merge($block->opCodes, $nestedProducerOps)) as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg2) {
                continue;
            }
            $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
            if ('true' === strtolower($name ?? '')) {
                $trueSlot = $op->arg1;
                break;
            }
        }
        if (null === $trueSlot) {
            $trueSlot = $this->slotForVarExportHoistedReturnTruePrelude($block, $cfgCallOp);
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $sendOrdinal) {
                $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero($block, $cfgCallOp);
                if (null !== $hoistedScalarArgSlot) {
                    // var_export(INF, true) twice — arg #0 is hoisted INF/NAN, not prior var_export EXEC_RETURN (#18426).
                    $send->arg1 = $hoistedScalarArgSlot;
                } elseif ([] !== $initSlots && \in_array($send->arg1, $initSlots, true)) {
                    $send->arg1 = $execSlot;
                } elseif (null !== $trueSlot && (string) $send->arg1 === (string) $trueSlot) {
                    // var_export($it->current(), true) / var_export(f(), true) — arg #0 is producer EXEC_RETURN (#17251).
                    $send->arg1 = $execSlot;
                } elseif (
                    null === $hoistedScalarArgSlot
                    && $callArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($callArg)
                    && (string) $send->arg1 !== (string) $execSlot
                ) {
                    // var_export($g->valid(), true) after prior var_export — dead arg temp must not reuse stale EXEC_RETURN (#17520).
                    $send->arg1 = $execSlot;
                } elseif (null === $hoistedScalarArgSlot && (string) $send->arg1 !== (string) $execSlot) {
                    // var_export($g2->current(), true) after earlier var_export — sibling MethodCall EXEC_RETURN (#18183).
                    $send->arg1 = $execSlot;
                }
            } elseif (1 === $sendOrdinal && null !== $trueSlot && (string) $send->arg1 === (string) $execSlot) {
                $send->arg1 = $trueSlot;
            }
            ++$sendOrdinal;
        }
        unset($send);
    }

}
