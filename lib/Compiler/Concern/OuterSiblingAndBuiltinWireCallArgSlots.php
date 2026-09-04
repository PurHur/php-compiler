<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Outer-sibling inline call-arg slots and builtin-specific hoisted wire helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see outerSiblingInlineCallArgProducerSlot}, nested ?: / Phi merge
 * remapping beside dead temps, and date_sun_info / array_splice / mbstring
 * unary-offset dedicated producer wiring used from compileCallArgSends.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait OuterSiblingAndBuiltinWireCallArgSlots
{
    /**
     * php-cfg array_intersect(f(g()), f(g())) — map arg N to outer hoisted producer slot (#15488).
     */
    private function outerSiblingInlineCallArgProducerSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?array &$emitOps = null
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2 || $argIndex >= $argCount) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $outer = $this->outerSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        $embeddedArgCount = 0;
        $deadInlineTempCount = 0;
        $hoistedArgCount = 0;
        foreach ($cfgCallOp->args as $hoistedArg) {
            if (null !== $hoistedArg && $this->isEmbeddedCallLiteralArg($hoistedArg)) {
                ++$embeddedArgCount;
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                ++$deadInlineTempCount;
            }
            if (null !== $hoistedArg) {
                ++$hoistedArgCount;
            }
        }
        if (\count($outer) === $argCount && ($embeddedArgCount === $argCount || $deadInlineTempCount === $argCount)) {
            // array_intersect(f(g()), f(g())) — hoisted outer producers only (#15488).
            $hoistedArgCount = $argCount;
        }
        if (\count($outer) !== $hoistedArgCount || \count($outer) !== $argCount) {
            return null;
        }
        if (\count($outer) >= $callIndex - $firstSibling) {
            return null;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — intervening StaticPropertyFetch are the
        // ARG_SEND sources; stmt-level StaticCalls in $outer must not win (#34997).
        // var_dump($g(), $h()) has no intervening fetches, so still binds $outer.
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return null;
        }
        $hoistedOrdinal = null;
        $seen = 0;
        foreach ($cfgCallOp->args as $i => $hoistedArg) {
            if (null !== $hoistedArg && !$this->isEmbeddedCallLiteralArg($hoistedArg)) {
                if ($i === $argIndex) {
                    $hoistedOrdinal = $seen;
                    break;
                }
                ++$seen;
            }
        }
        if (null === $hoistedOrdinal) {
            return null;
        }
        $outerProducer = $outer[$hoistedOrdinal] ?? null;
        if (!$outerProducer instanceof Op\Expr || null === $outerProducer->result) {
            return null;
        }
        if (
            $outerProducer instanceof Op\Expr\FuncCall
            || $outerProducer instanceof Op\Expr\NsFuncCall
        ) {
            if (null === $block->slotForOperand($outerProducer->result)) {
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $this->forceDeferredSiblingCallReturnSlot = true;
                try {
                    foreach ($this->compileExpr($outerProducer, $block) as $op) {
                        if (null !== $emitOps) {
                            $emitOps[] = $op;
                        } else {
                            $block->addOpCode($op);
                        }
                    }
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                }
            }
        }
        $operandSlot = $block->slotForOperand($outerProducer->result);
        if (null !== $operandSlot) {
            $outerProducerIndexForOperand = array_search($outerProducer, $block->orig->children, true);
            $prevProducer = is_int($outerProducerIndexForOperand) && $outerProducerIndexForOperand > 0
                ? ($block->orig->children[$outerProducerIndexForOperand - 1] ?? null)
                : null;
            // array_intersect(f(g()), f(g())) — operand slot beats ordinal EXEC_RETURN when emission order drifts (#16427).
            // in_array/array_search before var_dump — EXEC_RETURN ordinals must win (#9390, #17317).
            if (
                ($outerProducer instanceof Op\Expr\FuncCall || $outerProducer instanceof Op\Expr\NsFuncCall)
                && !$this->funcCallExprLiteralCalleeAllowedAsHoistedProducer($outerProducer)
                && !\in_array(
                    strtolower($this->resolveCfgFuncCallName($outerProducer) ?? ''),
                    ['in_array', 'array_search', 'array_key_exists', 'key_exists'],
                    true
                )
                && ($prevProducer instanceof Op\Expr\FuncCall || $prevProducer instanceof Op\Expr\NsFuncCall)
                && is_int($outerProducerIndexForOperand)
                && $this->isAdjacentNestedFuncCallProducer(
                    $prevProducer,
                    $outerProducer,
                    $outerProducerIndexForOperand - 1,
                    $outerProducerIndexForOperand
                )
            ) {
                return (string) $operandSlot;
            }
        }
        $outerProducerIndex = array_search($outerProducer, $block->orig->children, true);
        if (
            is_int($outerProducerIndex)
            && (
                $outerProducer instanceof Op\Expr\FuncCall
                || $outerProducer instanceof Op\Expr\NsFuncCall
            )
        ) {
            $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                $block,
                $outerProducerIndex,
                $block->orig->children
            );
            if (null !== $execReturn) {
                return (string) $execReturn;
            }
        }
        $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
            $block,
            $outerProducer,
            $cfgCallOp,
            $block->orig->children
        );
        if (null !== $execReturn) {
            return (string) $execReturn;
        }
        $slot = $block->slotForOperand($outerProducer->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Map dead inline ?: call-arg temps to the innermost ?: merge phi slots for this call (#15816, #22732, #36380).
     *
     * php-cfg writes Phi into Temporary->ops for ?: args and ConstFetch for trailing true/false/null.
     * Only Phi-written args consume ternary merge phi slots — counting all dead temps made
     * `f(cond ? a : b, true)` fail the phiSlots>=deadTempCount guard (#22732).
     * Lone `f(…, cond ? a : b)` must also remap: the ARG_SEND Dead temp is not the arm ASSIGN
     * target, so compileOperand leaves null (#36380).
     */
    private function resolveNestedTernaryMergeCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        Operand $arg
    ): ?string {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return null;
        }
        if (!$this->callArgIsDeadInlineTemporary($arg) || !$this->callArgTemporaryIsPhiWritten($arg)) {
            return null;
        }
        $phiArgIndexes = [];
        foreach ($cfgCallOp->args as $i => $callArg) {
            if (
                $callArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArg)
                && $this->callArgTemporaryIsPhiWritten($callArg)
            ) {
                $phiArgIndexes[] = (int) $i;
            }
        }
        if ([] === $phiArgIndexes) {
            return null;
        }
        // Multi-?: call args (#15816), mixed ?: + scalar ConstFetch (#22732), AND lone ?: as a
        // call argument (#36380 Parsedown htmlspecialchars / show($c?0:3)) all need remapping.
        // php-cfg leaves the ARG_SEND operand as a Dead Phi temp distinct from the arm ASSIGN
        // targets; compileOperand does not bridge that gap (historic "deadTempCount>=2" /
        // sibling-dead-temp gate left lone ?: as null → ENT_NOQUOTES / empty args).
        $phiSlots = [];
        foreach ($this->ternaryMergePhiRhsSlots as $mergeCfg) {
            $phi = $this->ternaryMergePhiRhsSlots[$mergeCfg];
            if (null !== $phi) {
                $phiSlots[] = $phi;
            }
        }
        $phiSlots = array_values(array_unique($phiSlots));
        $phiArgCount = \count($phiArgIndexes);
        if (\count($phiSlots) < $phiArgCount) {
            return null;
        }
        $phiSlots = \array_slice($phiSlots, -$phiArgCount);
        $ordinal = \array_search($argIndex, $phiArgIndexes, true);
        if (false === $ordinal || $ordinal >= \count($phiSlots)) {
            return null;
        }

        return (string) $phiSlots[$ordinal];
    }

    /** php-cfg Temporary written by a Phi — ?: / short-circuit merge result (#22732). */
    private function callArgTemporaryIsPhiWritten(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if ($embedded instanceof Op\Phi) {
                return true;
            }
        }

        return false;
    }

    /** php-cfg Temporary written by a hoisted true/false/null ConstFetch (#22732). */
    private function callArgTemporaryIsScalarConstFetchWritten(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if (!$embedded instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($embedded->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Sole writer on a dead call-arg temp — Array_ / ConstFetch / ClassConstFetch (#25337).
     *
     * When a sibling arg is ?: Phi-written, JumpIf merge prebind can make compileOperand
     * resolve the non-Phi temp to the ternary phi slot (array_merge([1], $x?[2]:[3]),
     * twoway(FLAG, 'C'?:'D')). Prefer the embedded writer instead.
     */
    private function soleEmbeddedWriterOnCallArgTemp(?Operand $arg): ?Op\Expr
    {
        if (null === $arg || !$this->callArgIsDeadInlineTemporary($arg)) {
            return null;
        }
        if ($this->callArgTemporaryIsPhiWritten($arg)) {
            return null;
        }
        $ops = $arg->ops ?? [];
        if (1 !== \count($ops) || !($ops[0] instanceof Op\Expr)) {
            return null;
        }
        $writer = $ops[0];
        if (
            $writer instanceof Op\Expr\Array_
            || $writer instanceof Op\Expr\ConstFetch
            || $writer instanceof Op\Expr\ClassConstFetch
        ) {
            return $writer;
        }

        return null;
    }

    /** True when another dead call arg on this call is ?: Phi-written (#25337). */
    private function cfgCallHasPhiWrittenDeadTempSibling(Op $cfgCallOp, int $argIndex): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $i => $candidate) {
            if ((int) $i === $argIndex || !($candidate instanceof Operand)) {
                continue;
            }
            if (
                $this->callArgIsDeadInlineTemporary($candidate)
                && $this->callArgTemporaryIsPhiWritten($candidate)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wire non-Phi dead temps beside ?: to their sole embedded writer slot (#25337).
     *
     * @param list<OpCode> $emitOps
     */
    private function resolveNonPhiSiblingOfTernaryCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        Operand $arg,
        array &$emitOps
    ): ?string {
        if (!$this->cfgCallHasPhiWrittenDeadTempSibling($cfgCallOp, $argIndex)) {
            return null;
        }
        $writer = $this->soleEmbeddedWriterOnCallArgTemp($arg);
        if (null === $writer || null === $writer->result) {
            return null;
        }
        $ternaryPhiSlots = [];
        foreach ($this->ternaryMergePhiRhsSlots as $mergeCfg) {
            $phi = $this->ternaryMergePhiRhsSlots[$mergeCfg];
            if (null !== $phi) {
                $ternaryPhiSlots[(string) $phi] = true;
            }
        }
        if ($writer instanceof Op\Expr\Array_) {
            $slot = $block->slotForOperand($writer->result);
            // Prebind may alias the Array_ result onto the ternary phi — emit a fresh INIT_ARRAY.
            if (null === $slot || isset($ternaryPhiSlots[(string) $slot])) {
                $arrayOps = $this->compileArrayLiteral($writer, $block);
                if ([] !== $arrayOps) {
                    $emitOps = array_merge($emitOps, $arrayOps);
                }
                $slot = $this->slotFromInitArrayLiteralOps($arrayOps)
                    ?? $block->slotForOperand($writer->result);
            }

            return null !== $slot ? (string) $slot : null;
        }
        if ($writer instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($writer);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($writer->result);
        if (null !== $slot && !isset($ternaryPhiSlots[(string) $slot])) {
            return (string) $slot;
        }
        foreach ($this->compileExpr($writer, $block) as $op) {
            $emitOps[] = $op;
        }
        $slot = $block->slotForOperand($writer->result);
        if (null !== $slot && !isset($ternaryPhiSlots[(string) $slot])) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * Hoisted ConstFetch wired to this positional arg must keep its slot (#15833).
     * probe('label', in_array(..., g(), true)) — ConstFetch feeds inner callee, not outer (#14237).
     */
    private function shouldRemapHoistedConstFetchToAdjacentNestedCall(
        Op\Expr $matched,
        Op $cfgCallOp,
        int $argIndex,
        ?Block $block = null
    ): bool {
        if (!$matched instanceof Op\Expr\ConstFetch) {
            return true;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return true;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            null !== $callArg
            && null !== $matched->result
            && $this->operandsReferToSameVariable($matched->result, $callArg)
        ) {
            return false;
        }
        if (null !== $block && null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
            $split = $this->splitLeadingConstFetchWithFuncCallCallArg($producers);
            if (null !== $split) {
                [$constFetch] = $split;
                if ($matched === $constFetch) {
                    $nonEmbeddedArgIndices = [];
                    foreach ($cfgCallOp->args as $i => $candidate) {
                        if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                            $nonEmbeddedArgIndices[] = (int) $i;
                        }
                    }
                    if (0 === array_search($argIndex, $nonEmbeddedArgIndices, true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * date_sunrise(time(), SUNFUNCS_RET_*, …) / date_sun_info(strtotime(...), lat, -lon) — hoisted FuncCall + prelude slots (#13749, #11070, #11336).
     */
    private function wireDateSunFuncHoistedCallArgSlot(Block $block, Op $cfgCallOp, int $argIndex): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        $isDateSunInfo = 'date_sun_info' === $callee;
        if (!\in_array($callee, ['date_sunrise', 'date_sunset', 'date_sun_info'], true)) {
            return null;
        }
        if (0 === $argIndex) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null === $callIndex) {
                return null;
            }
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\Assign) {
                    return null;
                }
                if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
                    continue;
                }
                $producerName = strtolower($this->resolveCfgFuncCallName($child) ?? '');
                if (!\in_array($producerName, ['time', 'gmmktime', 'strtotime'], true)) {
                    return null;
                }
                $producerIndex = $i;
                $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                    $block,
                    $child,
                    $cfgCallOp,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $operandSlot = $block->slotForOperand($child->result);
                if (null !== $operandSlot) {
                    return (string) $operandSlot;
                }
                if (null === $block->slotForOperand($child->result)) {
                    $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                    $this->forceDeferredSiblingCallReturnSlot = true;
                    try {
                        foreach ($this->compileExpr($child, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    } finally {
                        $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                    }
                }
                $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                    $block,
                    $child,
                    $cfgCallOp,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $operandSlot = $block->slotForOperand($child->result);

                return null !== $operandSlot ? (string) $operandSlot : null;
            }

            return null;
        }
        $longitudeArgIndex = $isDateSunInfo ? 2 : 3;
        if ($longitudeArgIndex === $argIndex) {
            foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
                if (!$prelude instanceof Op\Expr\UnaryMinus && !$prelude instanceof Op\Expr\UnaryPlus) {
                    continue;
                }
                $existing = $block->slotForOperand($prelude->result);
                if (null !== $existing) {
                    return (string) $existing;
                }
                $folded = $this->tryFoldUnaryLiteralDefault($prelude);
                if (null === $folded) {
                    continue;
                }

                return (string) $block->registerConstant($prelude->result, $folded);
            }

            return null;
        }
        if ($isDateSunInfo || 1 !== $argIndex) {
            return null;
        }
        foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
            if (!$prelude instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = strtolower($this->staticNameFromOperand($prelude->name) ?? '');
            if (!str_starts_with($name, 'sunfuncs_ret_')) {
                continue;
            }
            $existing = $block->slotForOperand($prelude->result);
            if (null !== $existing) {
                return (string) $existing;
            }
            $folded = $this->tryFoldGlobalConstFetch($prelude);
            if (null === $folded) {
                continue;
            }

            return (string) $block->registerConstant($prelude->result, $folded);
        }

        return null;
    }

    /**
     * array_splice($a, -N, $len, null) — UnaryMinus offset + null replacement (#16328, #10589).
     *
     * @param list<OpCode> $emitOps
     */
    private function wireArraySpliceUnaryOffsetReplacementCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        if ('array_splice' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return null;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 4) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $target = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            'array_splice'
        );
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if ($target instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        } elseif ($target instanceof Op\Expr\UnaryMinus || $target instanceof Op\Expr\UnaryPlus) {
            $folded = $this->tryFoldUnaryLiteralDefault($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($target->result);
        if (null === $slot) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
            $slot = $block->slotForOperand($target->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** array_splice($a, -N, $len, null) — skip generic hoisted-null prelude on offset/replacement slots (#16328). */
    private function arraySpliceUnaryOffsetReplacementUsesDedicatedProducerWiring(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        if ('array_splice' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 4) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);

        return null !== $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            'array_splice'
        );
    }

    /**
     * mb_substr($s, -N, null[, $enc]) / mb_strcut — UnaryMinus offset + null length (#16481).
     *
     * @param list<OpCode> $emitOps
     */
    private function wireMbstringUnaryOffsetNullLengthCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        $func = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true)) {
            return null;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 3) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $target = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            $func
        );
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if ($target instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        } elseif ($target instanceof Op\Expr\UnaryMinus || $target instanceof Op\Expr\UnaryPlus) {
            $folded = $this->tryFoldUnaryLiteralDefault($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($target->result);
        if (null === $slot) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
            $slot = $block->slotForOperand($target->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** mb_substr/mb_strcut($s, -N, null) — skip generic hoisted-null prelude on offset/length slots (#16481). */
    private function mbstringUnaryOffsetNullLengthUsesDedicatedProducerWiring(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $func = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true)) {
            return false;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 3) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);

        return null !== $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            $func
        );
    }
}
