<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg SEND-slot rewires (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers remaining rewire*ArgSendSlots (register_shutdown, preg_replace_callback,
 * array_combine, hoisted class-const prelude, nested var_export, …).
 * Arithmetic-branch / substr+sprintf / enum-prefix / sibling multi-arg peers live in
 * {@see RewireArithmeticBranchSubstrEnumAndSiblingMultiArgCallArgSendSlots}.
 * Bitmask / nested-file / var_export-flag peers (+ {@see slotForInlineExprResultInProducerOps})
 * live in {@see RewireInlineBitmaskNestedFileAndVarExportFlagCallArgSendSlots}.
 * Hoisted-sibling feed helpers + array_keys/combine rewire live in
 * {@see HoistedSiblingFeedAndArrayKeysArgSendRewire}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgSlotResolvers).
 */
trait RewireInlineCallArgSendSlots
{
    /**
     * register_shutdown_function(fn(...), E::A) — Closure + enum case hoisted siblings (#5751).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('register_shutdown_function' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 2 !== \count($cfgCallOp->args)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $closureExpr = $block->orig->children[$callIndex - 2] ?? null;
        $enumExpr = $block->orig->children[$callIndex - 1] ?? null;
        if (!($closureExpr instanceof Op\Expr\Closure || $closureExpr instanceof Op\Expr\ArrowFunction)) {
            return;
        }
        if (!$enumExpr instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $closureSlot = $block->slotForOperand($closureExpr->result);
        $enumSlot = $block->slotForOperand($enumExpr->result);
        if (null === $closureSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type) {
                    $closureSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $enumSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $enumSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $closureSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = 0 === $argIndex ? $closureSlot : $enumSlot;
            ++$argIndex;
        }
    }

    private function rewireHoistedClassConstPreludeCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || [] === $cfgCallOp->args) {
            return;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        if ($this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, 0)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        if (
            null !== $callArg
            && !$this->operandsReferToSameVariable($prelude->result, $callArg)
        ) {
            // array_pad([E::A], N, E::B) / extract([...], FLAGS, Prefix::A) — immediate ClassConstFetch is not arg #0 (#8883, #16041).
            // preg_replace_callback_array([...], E::CASE) — enum prelude feeds arg #1 (#5859).
            return;
        }
        if (
            'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
            && \is_int($callIndex)
            && $callIndex >= 2
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\Array_
        ) {
            return;
        }
        if (null === $block->slotForOperand($prelude->result)) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $preludeSlot = $block->slotForOperand($prelude->result);
        if (null === $preludeSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $preludeSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $preludeSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = (string) $preludeSlot;
            }
            ++$argIndex;
        }
    }

    /**
     * preg_replace_callback_array(['/pat/' => $cb], E::CASE) — pattern Array_ is arg #0, enum case arg #1 (#5859, #9072).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewirePregReplaceCallbackArrayPatternMapArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $allArgSends = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('preg_replace_callback_array' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $patternMap = $block->orig->children[$callIndex - 2] ?? null;
        $subjectPrelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$patternMap instanceof Op\Expr\Array_) {
            return;
        }
        if (!$subjectPrelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $initSlot = null;
        foreach (array_merge($block->opCodes, $allArgSends) as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initSlot = (string) $op->arg1;
            }
        }
        if (null === $initSlot) {
            $initSlot = $block->slotForOperand($patternMap->result);
            if (null !== $initSlot) {
                $initSlot = (string) $initSlot;
            }
        }
        $enumSlot = $block->slotForOperand($subjectPrelude->result);
        if (null === $enumSlot) {
            foreach ($this->compileExpr($subjectPrelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $enumSlot = $block->slotForOperand($subjectPrelude->result);
        }
        if (null === $initSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = $initSlot;
            } elseif (1 === $argIndex) {
                $send->arg1 = (string) $enumSlot;
            }
            ++$argIndex;
        }
        unset($send);
    }

    /**
     * array_combine([...], [...]) — ARG_SEND must map to sibling INIT_ARRAY slots, not recent-init (#16080, #10214, #17629).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewireArrayCombineInlineArgSendSlots(
        array &$outerArgSends,
        Block $block,
        array $allArgSends,
        ?string $calleeName,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || 2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $calleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('array_combine' !== $calleeLower) {
            return;
        }
        if (null === $block->orig) {
            return;
        }
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        ) as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                // array_combine(array_keys(...), [...]) — nested FuncCall feeds arg #0 (#16097).
                return;
            }
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $allArgSends);
        if (\count($initSlots) > 2) {
            // [] === array_combine([], []) — comparison lhs Array_ shares the post-call INIT_ARRAY window (#17629).
            $initSlots = \array_slice($initSlots, -2);
        }
        if (\count($initSlots) < 2) {
            return;
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (isset($initSlots[$sendOrdinal])) {
                $send->arg1 = $initSlots[$sendOrdinal];
            }
            ++$sendOrdinal;
        }
        unset($send);
    }

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
