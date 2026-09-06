<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * INIT_ARRAY / array-spread / inline-arithmetic / nested-subject call-arg
 * slot resolvers (#36387 / prior #36147).
 *
 * Extracted from {@see SlotForCallArgResolvers} so gen-0 split-TU can hollow a
 * smaller Concern TU (`slotForRecentInitArrayCallArg` →
 * `nestedInlineFuncCallProducerForCallArg`). Complementary to
 * {@see SlotForPropertyClassConstAndClosureBindCallArgResolvers} and
 * {@see SlotForInlineClosureAndFirstClassCallableCallArgResolvers}.
 * Named-assign helpers remain on SlotForCallArgResolvers.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SlotForCallArgResolvers).
 */
trait InitArraySpreadArithmeticAndNestedInlineCallArgResolvers
{
    /** Last TYPE_INIT_ARRAY before the current call — php-cfg dead arg temp vs array literal (#11586). */
    private function slotForRecentInitArrayCallArg(Block $block): ?string
    {
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * INIT_ARRAY slot after FUNCCALL_INIT / enum prelude opcodes for the active call (#5859).
     */
    private function slotForInitArrayBeforeCurrentFunccall(Block $block): ?string
    {
        $slots = $this->initArraySlotsForCurrentFunccall($block);

        return $slots[0] ?? null;
    }

    /**
     * INIT_ARRAY result slots for the active call — since last FUNCCALL_EXEC_RETURN (#17629).
     *
     * @param list<OpCode> $pendingOps
     *
     * @return list<string>
     */
    private function initArraySlotsForCurrentFunccall(Block $block, array $pendingOps = []): array
    {
        $ops = array_merge($block->opCodes, $pendingOps);
        $start = 0;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $ops[$i]->type) {
                $start = $i + 1;
                break;
            }
        }
        $slots = [];
        for ($i = $start, $n = \count($ops); $i < $n; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $slots[] = (string) $op->arg1;
            }
        }

        return $slots;
    }

    /**
     * Result slot of the last TYPE_ARRAY_SPREAD after FUNCCALL_EXEC_RETURN (#24645).
     *
     * Distinguishes `[...new ArrayIterator($ctorArray)]` (spread result) from the ctor
     * Array_ when both appear around the same call-arg wiring.
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForArraySpreadResultAfterLastExecReturn(Block $block, array $pendingOps = []): ?string
    {
        $ops = array_merge($block->opCodes, $pendingOps);
        $start = 0;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            $type = $ops[$i]->type;
            // EXEC_NORETURN (var_export) must bound like EXEC_RETURN so a later plain
            // array arg is not rewired to a prior [...$x] spread slot (#24645).
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $type
            ) {
                $start = $i + 1;
                break;
            }
        }
        $slot = null;
        for ($i = $start, $n = \count($ops); $i < $n; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_ARRAY_SPREAD === $op->type && null !== $op->arg1) {
                $slot = (string) $op->arg1;
            }
        }

        return $slot;
    }

    /**
     * Rewire sole dead-array ARG_SEND to the ARRAY_SPREAD result after nested New_ (#24645).
     *
     * @param list<OpCode> $argSends
     *
     * @return list<OpCode>
     */
    private function rewriteCallArgSendsForArraySpreadResult(array $argSends, Block $block, ?Op $cfgCallOp): array
    {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return $argSends;
        }
        $spreadSlot = $this->slotForArraySpreadResultAfterLastExecReturn($block, $argSends);
        if (null === $spreadSlot) {
            return $argSends;
        }
        $sendOps = [];
        foreach ($argSends as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendOps[] = $op;
            }
        }
        if (1 !== \count($sendOps)) {
            return $argSends;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || !(
                $this->callArgOperandExpectsArrayProducer($callArg)
                || $this->callArgIsDeadUnknownOrMixedTemporary($callArg)
            )
        ) {
            return $argSends;
        }
        $send = $sendOps[0];
        if ((string) $send->arg1 !== $spreadSlot) {
            $send->arg1 = $spreadSlot;
        }

        return $argSends;
    }

    /**
     * Stmt-before inline Array_ for a call arg — operand slot or ordinal INIT_ARRAY in opcode stream (#16418).
     */
    private function slotForInitArrayProducerBeforeCfgCall(
        Block $block,
        Op $cfgCallOp,
        ?Op\Expr\Array_ $arrayProducer = null,
        array $pendingOps = []
    ): ?string {
        $arrayProducer ??= $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (!$arrayProducer instanceof Op\Expr\Array_) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren) {
            return $block->slotForOperand($arrayProducer->result) !== null
                ? (string) $block->slotForOperand($arrayProducer->result)
                : null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            $slot = $block->slotForOperand($arrayProducer->result);

            return null !== $slot ? (string) $slot : null;
        }
        $targetOrdinal = null;
        $arrayOrdinal = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i];
            if ($child instanceof Op\Expr\Array_) {
                if ($child === $arrayProducer) {
                    $targetOrdinal = $arrayOrdinal;
                    break;
                }
                ++$arrayOrdinal;
            }
        }
        if (null !== $targetOrdinal) {
            $seen = 0;
            foreach ($pendingOps as $op) {
                if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                    continue;
                }
                if ($seen === $targetOrdinal) {
                    return (string) $op->arg1;
                }
                ++$seen;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                    continue;
                }
                if ($seen === $targetOrdinal) {
                    return (string) $op->arg1;
                }
                ++$seen;
            }
        }
        $slot = $block->slotForOperand($arrayProducer->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * CFG branch blocks inherit stale operand slots — wire decoct($x & 0777) to the fresh AND dest (#15902).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForRecentInlineArithmeticCallArg(Block $block, array $emitOps): ?string
    {
        // Do not cross an intervening call result — e.g. json_encode(iterator_to_array(...))
        // after `new C(..., CONST|CONST)` must not steal the bitmask OR slot (#24369 / #10474).
        $fromOps = $this->slotForRecentInlineArithmeticCallArgInOps($emitOps);
        if (null !== $fromOps) {
            return $fromOps;
        }

        return $this->slotForRecentInlineArithmeticCallArgInOps($block->opCodes);
    }

    /**
     * @param list<OpCode> $ops
     */
    private function slotForRecentInlineArithmeticCallArgInOps(array $ops): ?string
    {
        $skippedCurrentCallInit = false;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if (!$skippedCurrentCallInit) {
                    $skippedCurrentCallInit = true;
                    continue;
                }

                return null;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type
            ) {
                return null;
            }
            if ($this->isInlineArithmeticResultOpcode($op->type)) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    private function isInlineArithmeticResultOpcode(int $type): bool
    {
        return \in_array($type, [
            OpCode::TYPE_BITWISE_AND,
            OpCode::TYPE_BITWISE_OR,
            OpCode::TYPE_BITWISE_XOR,
            OpCode::TYPE_PLUS,
            OpCode::TYPE_MINUS,
            OpCode::TYPE_MUL,
            OpCode::TYPE_DIV,
            OpCode::TYPE_MODULO,
            OpCode::TYPE_POW,
            OpCode::TYPE_SHIFT_LEFT,
            OpCode::TYPE_SHIFT_RIGHT,
        ], true);
    }

    /**
     * Dead inline array call arg: prefer nested FUNCCALL_EXEC_RETURN over sibling INIT_ARRAY (#14042).
     *
     * When php-cfg marks a nested FuncCall result temp dead, compileCallArgSends must not wire the
     * consumer to the nested call's INIT_ARRAY argument slot.
     */
    private function slotForDeadInlineArrayOrCallResultCallArg(Block $block, Op $cfgCallOp, int $argIndex): ?string
    {
        $embeddedArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, $argIndex, $block);
        if ($embeddedArray instanceof Op\Expr\Array_) {
            $embeddedSlot = $block->slotForOperand($embeddedArray->result);
            if (null !== $embeddedSlot) {
                return (string) $embeddedSlot;
            }
        }
        if (!$this->callArgIsNestedFuncCallResult($cfgCallOp, $argIndex, $block)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (
                !(
                    'array_keys' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && $callArg instanceof Operand
                    && $this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, $argIndex)
                )
            ) {
                $immediateSlot = $this->slotForInitArrayProducerBeforeCfgCall($block, $cfgCallOp);
                if (null !== $immediateSlot) {
                    return $immediateSlot;
                }
            }

            return $this->slotForRecentInitArrayCallArg($block);
        }
        if (null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
            $immediate = $producers[0] ?? null;
            if (
                $immediate instanceof Op\Expr\FuncCall
                || $immediate instanceof Op\Expr\NsFuncCall
                || $immediate instanceof Op\Expr\MethodCall
                || $immediate instanceof Op\Expr\StaticCall
            ) {
                $producerSlot = $block->slotForOperand($immediate->result);
                if (null !== $producerSlot) {
                    return (string) $producerSlot;
                }
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                if (null !== $block->orig) {
                    $callIndex = null;
                    foreach ($block->orig->children as $ci => $child) {
                        if ($child === $cfgCallOp) {
                            $callIndex = $ci;
                            break;
                        }
                    }
                    if (null !== $callIndex) {
                        for ($pi = $callIndex - 1; $pi >= 0; --$pi) {
                            $prior = $block->orig->children[$pi] ?? null;
                            if ($prior instanceof Op\Expr\FuncCall || $prior instanceof Op\Expr\NsFuncCall) {
                                if ($this->statementLevelFuncCallBeforeHoistedSiblingChain(
                                    $pi,
                                    $callIndex,
                                    $block->orig->children
                                )) {
                                    return $this->slotForRecentInitArrayCallArg($block);
                                }
                                break;
                            }
                            if (
                                $prior instanceof Op\Expr\MethodCall
                                || $prior instanceof Op\Expr\StaticCall
                            ) {
                                break;
                            }
                            if (!$prior instanceof Op\Expr\ConstFetch
                                && !$prior instanceof Op\Expr\ClassConstFetch
                                && !$prior instanceof Op\Expr\Array_
                                && !$this->isUnaryInlineSiblingCallArgExpr($prior)
                            ) {
                                break;
                            }
                        }
                    }
                }

                return (string) $op->arg1;
            }
        }

        return $this->slotForRecentInitArrayCallArg($block);
    }

    /**
     * json_encode(nested(), …) — keep outer FUNCCALL_INIT after nested inline producers (#34559).
     *
     * Hoisted JSON_* ConstFetch for flags triggers prependFuncCallInitBeforeTrailingArgConstFetches
     * (#17697) and left outer INIT before inner f()/make_list() under AOT. Dead arg temps may not
     * share cfg roots with the nested FuncCall result, so detect sibling FuncCall producers.
     */
    private function jsonEncodeDeferredInitForNestedCallArg(Op $cfgCallOp, Block $block): bool
    {
        if ('json_encode' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if ($this->callArgIsNestedFuncCallResult($cfgCallOp, 0, $block)) {
            return true;
        }
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return false;
        }
        $sibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $sibling) {
            return false;
        }
        $flagsArg = $cfgCallOp->args[1] ?? null;
        if (!$flagsArg instanceof Operand) {
            return true;
        }
        $flagsProducer = $this->findCfgProducerExprForOperand($flagsArg);
        if ($flagsProducer instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($flagsProducer->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'JSON_')) {
                return true;
            }
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if ($prev instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($prev->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'JSON_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * str_pad()/mb_str_pad(…, nested(), STR_PAD_*) — keep outer FUNCCALL_INIT after nested producers (#34890).
     *
     * Hoisted STR_PAD_* (or user) ConstFetch for pad_type triggers prependFuncCallInitBeforeTrailingArgConstFetches
     * (#17697) and left outer INIT before nested pad_string/encoding FuncCalls under VM — return became the
     * nested call value. Peer {@see jsonEncodeDeferredInitForNestedCallArg} / #34559.
     *
     * CFG may order ConstFetch before the nested FuncCall (`STR_PAD_*` then `enc()` then `mb_str_pad`), so
     * pad_type Operand may be a dead temp — sibling FuncCall detection is the reliable signal.
     */
    private function strPadDeferredInitForNestedCallArg(Op $cfgCallOp, Block $block): bool
    {
        $name = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('str_pad' !== $name && 'mb_str_pad' !== $name) {
            return false;
        }
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $argc = \count($cfgCallOp->args);
        for ($i = 0; $i < $argc; ++$i) {
            if ($this->callArgIsNestedFuncCallResult($cfgCallOp, $i, $block)) {
                return true;
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return false;
        }

        return null !== $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
    }

    /** True when a hoisted nested FuncCall feeds this dead inline call arg (#14042). */
    private function callArgIsNestedFuncCallResult(Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if (!is_array($cfgCallOp->args ?? null) || null === $block->orig) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg) {
            return false;
        }
        if ($callArg instanceof Operand) {
            $embeddedArray = $this->unwrapArrayLiteralExpr($callArg);
            if (null === $embeddedArray) {
                $producer = $this->findCfgProducerExprForOperand($callArg);
                if ($producer instanceof Op\Expr\Array_) {
                    $embeddedArray = $producer;
                }
            }
            if ($embeddedArray instanceof Op\Expr\Array_) {
                return false;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        foreach ($producers as $producer) {
            if (
                $producer instanceof Op\Expr\Array_
                && $this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArg)
            ) {
                return false;
            }
        }
        foreach ($producers as $producer) {
            if (
                !$producer instanceof Op\Expr\FuncCall
                && !$producer instanceof Op\Expr\NsFuncCall
                && !$producer instanceof Op\Expr\StaticCall
                && !$producer instanceof Op\Expr\MethodCall
            ) {
                continue;
            }
            if ($this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArg)) {
                return true;
            }
        }
        $immediate = $producers[0] ?? null;
        if (
            (
                $immediate instanceof Op\Expr\FuncCall
                || $immediate instanceof Op\Expr\NsFuncCall
                || $immediate instanceof Op\Expr\StaticCall
                || $immediate instanceof Op\Expr\MethodCall
            )
            && (int) $argIndex > 0
            && $this->callArgIsDeadInlineTemporary($callArg)
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return true;
        }

        return false;
    }

    /**
     * FUNCCALL_EXEC_RETURN immediately before hoisted ConstFetch prelude — nested subject (#13617).
     *
     * compileCallArgSends runs before FUNCCALL_INIT is appended, so the tail is often CONST_FETCH
     * with the nested call's EXEC_RETURN one slot earlier (filter_var(sprintf(...), FILTER_*)).
     */
    private function slotForNestedSubjectExecBeforeLiteralPreludeCall(Block $block): ?string
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        if ($n < 2) {
            return null;
        }
        $execIndex = null;
        $tail = $ops[$n - 1];
        if (
            OpCode::TYPE_CONST_FETCH === $tail->type
            || OpCode::TYPE_CLASS_CONST_FETCH === $tail->type
        ) {
            $execIndex = $n - 2;
        } elseif (OpCode::TYPE_FUNCCALL_INIT === $tail->type) {
            if ($n < 3) {
                return null;
            }
            $beforeInit = $ops[$n - 2];
            if (
                OpCode::TYPE_CONST_FETCH !== $beforeInit->type
                && OpCode::TYPE_CLASS_CONST_FETCH !== $beforeInit->type
            ) {
                return null;
            }
            $execIndex = $n - 3;
        } elseif (
            OpCode::TYPE_STATICCALL_INIT === $tail->type
            || OpCode::TYPE_METHODCALL_INIT === $tail->type
        ) {
            if ($n < 3) {
                return null;
            }
            $beforeInit = $ops[$n - 2];
            if (
                OpCode::TYPE_CONST_FETCH !== $beforeInit->type
                && OpCode::TYPE_CLASS_CONST_FETCH !== $beforeInit->type
            ) {
                return null;
            }
            $execIndex = $n - 3;
        } else {
            return null;
        }
        $exec = $ops[$execIndex] ?? null;
        if (null === $exec || OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $exec->type) {
            return null;
        }

        return (string) $exec->arg1;
    }

    /**
     * Nested FuncCall subject immediately before hoisted literal preludes (filter_var(sprintf(...), FILTER_*); #13617).
     */
    private function nestedInlineFuncCallProducerForCallArg(Block $block, Op $cfgCallOp, int $argIndex): ?Op\Expr
    {
        if (null === $block->orig) {
            return null;
        }
        if ($argIndex > 0 && is_array($cfgCallOp->args ?? null)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $callArg) {
                foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
                    if (
                        !$producer instanceof Op\Expr\FuncCall
                        && !$producer instanceof Op\Expr\NsFuncCall
                        && !$producer instanceof Op\Expr\StaticCall
                        && !$producer instanceof Op\Expr\MethodCall
                    ) {
                        continue;
                    }
                    if (
                        null !== $producer->result
                        && (
                            $producer->result === $callArg
                            || $this->operandsReferToSameVariable($producer->result, $callArg)
                        )
                    ) {
                        return $producer;
                    }
                }
            }

            return null;
        }
        $consumerIndex = null;
        foreach ($block->orig->children as $ci => $child) {
            if ($child === $cfgCallOp) {
                $consumerIndex = $ci;
                break;
            }
        }
        if (null === $consumerIndex) {
            return null;
        }
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
            if (
                !$producer instanceof Op\Expr\FuncCall
                && !$producer instanceof Op\Expr\NsFuncCall
                && !$producer instanceof Op\Expr\StaticCall
                && !$producer instanceof Op\Expr\MethodCall
            ) {
                continue;
            }
            $producerIndex = null;
            foreach ($block->orig->children as $pi => $child) {
                if ($child === $producer) {
                    $producerIndex = $pi;
                    break;
                }
            }
            if (null === $producerIndex) {
                continue;
            }
            if ($this->isNestedCallArgProducerForConsumer(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $consumerIndex,
                $block->orig->children
            ) || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $consumerIndex,
                $block->orig->children
            )) {
                return $producer;
            }
        }

        return null;
    }

}
