<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * Adjacent nested FuncCall / Assign-in-call and inline-hoisted call-arg slots (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see resolveAdjacentNestedFuncCallArgSlot} and the nested-call /
 * assign-RHS / stmt side-effect helpers it shares.
 * Hoisted unary / assign-in-call / inline producer ordinals live in
 * {@see HoistedUnaryAssignAndInlineProducerCallArgSlots}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait AdjacentNestedCallArgSlots
{
    /**
     * importNode($doc->documentElement, true) — PropertyFetch sibling before trailing ConstFetch (#16318).
     *
     * @param list<Op> $cfgChildren
     */
    private function nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes(
        int $consumerIndex,
        array $cfgChildren
    ): ?Op\Expr {
        if ($consumerIndex < 2) {
            return null;
        }
        $probeIndex = $consumerIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $cfgChildren[$probeIndex] ?? null;
            // Skip any trailing ConstFetch (true/false/null or LIBXML_*, PATH_SEPARATOR, …) (#25292).
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            if ($probe instanceof Op\Expr\Assign) {
                --$probeIndex;
                continue;
            }
            if ($probe instanceof Op\Expr && $this->isInlineExprCallArgProducer($probe)) {
                if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                    return null;
                }

                return $probe;
            }

            return null;
        }

        return null;
    }

    /**
     * Wire a dead call-arg temp to an adjacent nested FuncCall/MethodCall EXEC_RETURN.
     *
     * Must not bind every dead temp to the adjacent call: `Ack($m - 1, Ack($m, $n - 1))` hoists a
     * BinaryOp for arg #0 and a nested FuncCall for arg #1; stealing the nested result for both
     * produced `Ack(r, r)` and AOT segfaults (#23472; same class as #23354 exact producer link).
     */
    private function resolveAdjacentNestedFuncCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $args = $cfgCallOp->args;
        $callArg = $args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        // php-cfg records the real producer as the arg temp's sole writer. When that writer is a
        // non-call inline expr (BinaryOp, UnaryMinus, …), leave the slot for exactHoisted (#23472).
        if ($callArg instanceof Operand && 1 === \count($callArg->ops ?? [])) {
            $soleWriter = $callArg->ops[0];
            if (
                $soleWriter instanceof Op\Expr
                && $this->isInlineExprCallArgProducer($soleWriter)
                && !(
                    $soleWriter instanceof Op\Expr\FuncCall
                    || $soleWriter instanceof Op\Expr\NsFuncCall
                    || $soleWriter instanceof Op\Expr\MethodCall
                    || $soleWriter instanceof Op\Expr\StaticCall
                    || $soleWriter instanceof Op\Expr\New_
                )
            ) {
                return null;
            }
        }
        if (
            0 === $argIndex
            && 'array_map' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
        ) {
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                $fccSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                if (null !== $fccSlot) {
                    return (string) $fccSlot;
                }
            } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                || $leadingCallback instanceof Op\Expr\Closure) {
                $closureSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                if (null !== $closureSlot) {
                    return (string) $closureSlot;
                }
            }
            $precedingSlot = $this->resolvePrecedingClosureCallArgSlot($cfgCallOp, $argIndex, $block);
            if (null !== $precedingSlot) {
                return (string) $precedingSlot;
            }
            for ($scan = \count($block->opCodes) - 1; $scan >= 0; --$scan) {
                $scanOp = $block->opCodes[$scan];
                if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                    return (string) $scanOp->arg1;
                }
            }
        }
        // array_map(null, [[..]]) — null ConstFetch is the callback, not a nested-call prelude (#9143).
        if (
            0 === $argIndex
            && 'array_map' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && null !== $this->arrayMapNullCallbackProducerBeforeCfgCall($cfgCallOp, $block)
        ) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (\is_int($callIndex) && $callIndex > 0) {
            $immediatePrelude = $block->orig->children[$callIndex - 1] ?? null;
            // two('L', $el->tagName) — PropertyFetch prelude is not a nested call producer; do not
            // fall through to a prior @$doc->loadXML() EXEC_RETURN (#21439).
            if (
                $immediatePrelude instanceof Op\Expr\PropertyFetch
                || $immediatePrelude instanceof Op\Expr\NullsafePropertyFetch
            ) {
                return null;
            }
            // method_exists(I::class, 'm') after var_dump(...) — immediate ::class prelude is arg #0 (#9486).
            if ($immediatePrelude instanceof Op\Expr\ClassConstFetch) {
                // tempnam(g(), E::A) — skip trailing enum; probe loop finds nested FuncCall (#10303, #16558).
                if (
                    null === $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                        $cfgCallOp,
                        $callIndex,
                        $block->orig->children
                    )
                ) {
                    return null;
                }
            }
        }
        $outerSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, $argIndex);
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        if (1 === \count($args) && 0 !== $argIndex) {
            return null;
        }
        if (null === $callIndex) {
            return null;
        }
        if ($callIndex < 1) {
            if (1 !== \count($args)) {
                return null;
            }
            $recent = $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
            if (null !== $recent) {
                return (string) $recent;
            }

            return null;
        }
        $probeIndex = $callIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $block->orig->children[$probeIndex] ?? null;
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($probe)) {
                --$probeIndex;
                continue;
            }
            break;
        }
        $prev = $block->orig->children[$probeIndex] ?? null;
        if ($prev instanceof Op\Expr\MethodCall || $prev instanceof Op\Expr\StaticCall) {
            if (
                !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $prev,
                    $cfgCallOp,
                    $probeIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return null;
            }
            // importNode($list->item(0), true) — leaf MethodCall feeds arg #0; trailing
            // true/false/null ConstFetch maps to later args (#20284, re-#18860 MethodCall).
            if ($argIndex > 0) {
                $immediatePrelude = $block->orig->children[$callIndex - 1] ?? null;
                if ($immediatePrelude instanceof Op\Expr\ConstFetch) {
                    $scalarName = $this->staticNameFromOperand($immediatePrelude->name);
                    if (
                        null !== $scalarName
                        && \in_array(strtolower($scalarName), ['true', 'false', 'null'], true)
                    ) {
                        return null;
                    }
                }
            }
            $operandSlot = $block->slotForOperand($prev->result);
            if (null !== $operandSlot) {
                return (string) $operandSlot;
            }
            $methodSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                $block,
                $prev,
                $cfgCallOp,
                $block->orig->children
            );
            if (null !== $methodSlot) {
                return $methodSlot;
            }

            return null;
        }
        if ($prev instanceof Op\Expr\New_) {
            if (
                0 === $argIndex
                && [] !== $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp)
            ) {
                return null;
            }
            if (
                0 !== $argIndex
                || !\is_array($cfgCallOp->args ?? null)
                || \count($cfgCallOp->args) < 2
            ) {
                return null;
            }
            $callArg = $cfgCallOp->args[0] ?? null;
            if (
                !$this->callArgIsNewExpression($callArg)
                && (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg))
            ) {
                return null;
            }
            if (null === $block->slotForOperand($prev->result)) {
                foreach ($this->compileExpr($prev, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
            $newSlot = $this->slotForInlineNewProducer($block, $prev);
            if (null !== $newSlot) {
                return (string) $newSlot;
            }

            return null;
        }
        if (
            !($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
            || !$this->isNestedCallArgProducerForConsumer(
                $prev,
                $cfgCallOp,
                $probeIndex,
                $callIndex,
                $block->orig->children
            )
        ) {
            return null;
        }
        if (1 === ($callIndex - $probeIndex)) {
            // Dead temp whose sole writer is a *different* nested FuncCall must not steal the
            // immediately-previous EXEC_RETURN — that produced passphrase=iv=str_repeat('i')
            // for openssl_decrypt(..., str_repeat('k'), 0, str_repeat('i')) before a later ?:
            // (#35879 / Ack-class #23472). Leave null for exactHoistedCallArgProducerSlot.
            if (
                $callArg instanceof Operand
                && 1 === \count($callArg->ops ?? [])
            ) {
                $soleWriter = $callArg->ops[0];
                if (
                    (
                        $soleWriter instanceof Op\Expr\FuncCall
                        || $soleWriter instanceof Op\Expr\NsFuncCall
                    )
                    && $soleWriter !== $prev
                ) {
                    return null;
                }
            }
            if (null === $block->slotForOperand($prev->result)) {
                foreach ($this->compileExpr($prev, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
            // probe('label', in_array(..., g(), true)) — adjacent callee EXEC_RETURN, not haystack ordinal (#16253).
            $lastAdjacentExecReturn = $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
            if (null !== $lastAdjacentExecReturn) {
                return (string) $lastAdjacentExecReturn;
            }
            // Prefer sibling EXEC_RETURN ordinal — cfg-index lookup counts TYPE_NEW pseudo returns (#16241).
            $adjacentExecReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                $block,
                $prev,
                $cfgCallOp,
                $block->orig->children
            );
            if (null === $adjacentExecReturn) {
                $adjacentExecReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $probeIndex,
                    $block->orig->children
                );
            }
            if (null !== $adjacentExecReturn) {
                $targetForAdjacent = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $probeIndex,
                    $callIndex,
                    $block->orig->children
                );
                if (
                    \count($args) < 2
                    || null === $targetForAdjacent
                    || $argIndex === $targetForAdjacent
                ) {
                    // var_dump($s, gettype($s)) — adjacent producer feeds matching dead-temp arg (#11144).
                    return (string) $adjacentExecReturn;
                }
            }
        }
        $nestedTargetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $probeIndex,
            $callIndex,
            $block->orig->children
        );
        if (null === $nestedTargetArgIndex) {
            $nestedTargetArgIndex = 0;
            while (
                $nestedTargetArgIndex < \count($args)
                && $this->isEmbeddedCallLiteralArg($args[$nestedTargetArgIndex] ?? null)
            ) {
                ++$nestedTargetArgIndex;
            }
        }
        if ($argIndex !== $nestedTargetArgIndex) {
            if (1 !== ($callIndex - $probeIndex)) {
                $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
                $producerOrdinal = null;
                if (null !== $firstSibling) {
                    $producerOrdinal = $this->siblingInlineFuncCallProducerOrdinalAtIndex(
                        $probeIndex,
                        $firstSibling,
                        $callIndex,
                        $block->orig->children
                    );
                }
                $deadTempOrdinal = $this->deadInlineTemporaryArgOrdinalBeforeIndex($args, $argIndex);
                if (null === $producerOrdinal || $deadTempOrdinal !== $producerOrdinal) {
                    return null;
                }
            }
            // var_dump($s, gettype($s)) — stmt-adjacent hoisted producer for a later dead temp arg (#11144).
        }
        $gapMid = $block->orig->children[$probeIndex + 1] ?? null;
        $gapIsUnaryOnly = ($callIndex - $probeIndex) === 2
            && $this->isUnaryInlineSiblingCallArgExpr($gapMid);
        if (
            null !== $callArg
            && !$this->operandsReferToSameVariable($prev->result, $callArg)
            && 1 !== ($callIndex - $probeIndex)
            && !$gapIsUnaryOnly
        ) {
            return null;
        }
        if (null === $block->slotForOperand($prev->result)) {
            foreach ($this->compileExpr($prev, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $prevSlot = $block->slotForOperand($prev->result);
        $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
            $block,
            $probeIndex,
            $block->orig->children
        ) ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
        if (
            null !== $prevSlot
            && null !== $execReturn
            && $prevSlot !== $execReturn
            && 1 !== ($callIndex - $probeIndex)
            && $this->isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer($cfgCallOp, $block)
        ) {
            // array_intersect(str_split(str_repeat()), …) — sibling g() already emitted; last EXEC_RETURN is a later g() (#16031, #15488).
            return (string) $prevSlot;
        }
        if (null !== $execReturn) {
            if (\count($args) >= 2) {
                $targetForProbe = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $probeIndex,
                    $callIndex,
                    $block->orig->children
                );
                if (null !== $targetForProbe && $argIndex !== $targetForProbe) {
                    return null;
                }
            }

            return (string) $execReturn;
        }

        return null !== $prevSlot ? (string) $prevSlot : null;
    }

    /**
     * array_merge(['a'=>1], array_keys(...)) — arg #0 maps to hoisted leading Array_, not adjacent FuncCall (#16299, #13760).
     */
    private function arrayMergeFamilyLeadingInlineArrayArgUsesHoistedArray(Op $cfgCallOp, int $argIndex, ?Block $block): bool
    {
        if (0 !== $argIndex || null === $block || null === $block->orig) {
            return false;
        }
        $calleeLower = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array(
            $calleeLower,
            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
            true
        )) {
            return false;
        }
        $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
            $block->orig->children,
            $cfgCallOp
        );
        if (\count($mergeProducers) < 2) {
            return false;
        }
        $mapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
            $mergeProducers,
            0,
            \count($cfgCallOp->args ?? []),
            is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
        );

        return $mapped instanceof Op\Expr\Array_;
    }

    /**
     * Final adjacent nested probe must not clobber callee-specific arg0 wiring (#16023, #13775).
     */
    private function shouldSkipFinalAdjacentNestedFuncCallArgProbe(Op $cfgCallOp, int $argIndex, ?Block $block = null): bool
    {
        if (!\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        // f(g()) before array_intersect(f(g()), f(g())) — outer sibling ordinal wiring (#16242, #15488).
        if (null !== $block && $this->isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer($cfgCallOp, $block)) {
            return true;
        }
        // Multi-array set ops with 2+ hoisted array operands — sibling ordinal wiring (#16242, #15947).
        $calleeLower = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ($this->shouldUseArrayProducerCallArgResolution($cfgCallOp, $argIndex, $calleeLower)) {
            $deadArrayHoisted = 0;
            foreach ($cfgCallOp->args as $callArg) {
                if (
                    $this->callArgIsDeadInlineTemporary($callArg)
                    && $this->callArgOperandExpectsArrayProducer($callArg)
                ) {
                    ++$deadArrayHoisted;
                }
            }
            if ($deadArrayHoisted >= 2) {
                // array_merge(array_keys(...), ['b']) — arg #0 is sibling FuncCall, not hoisted leading Array_ (#12450).
                if (
                    \in_array(
                        $calleeLower,
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && 0 === $argIndex
                    && null !== $block
                    && !$this->arrayMergeFamilyLeadingInlineArrayArgUsesHoistedArray($cfgCallOp, $argIndex, $block)
                ) {
                    return false;
                }

                return true;
            }
        }
        if (0 !== $argIndex) {
            return false;
        }
        $leadingArg = $cfgCallOp->args[0] ?? null;
        if (!$leadingArg instanceof Operand || !$this->callArgOperandExpectsArrayProducer($leadingArg)) {
            return false;
        }

        return 'array_slice' === $calleeLower
            || 'var_export' === $calleeLower
            || $this->arrayMergeFamilyLeadingInlineArrayArgUsesHoistedArray($cfgCallOp, $argIndex, $block);
    }

    /**
     * Sole dead call-arg temp fed by immediately preceding MethodCall/StaticCall (#14555).
     *
     * Scoped to compileCallArgs ARG_SEND override — not resolveAdjacentNestedFuncCallArgSlot.
     */
    private function resolveImmediatePrecedingCallProducerArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        Operand $arg
    ): ?string {
        if (null === $block->orig || !$this->callArgIsDeadInlineTemporary($arg)) {
            return null;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $args = $cfgCallOp->args;
        if (1 !== \count($args) || 0 !== $argIndex) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if ($prev instanceof Op\Expr\Array_) {
            return null;
        }
        if (
            !($prev instanceof Op\Expr\MethodCall
                || $prev instanceof Op\Expr\NullsafeMethodCall
                || $prev instanceof Op\Expr\StaticCall)
            || !$this->isAdjacentNestedFuncCallProducer($prev, $cfgCallOp, $callIndex - 1, $callIndex)
        ) {
            return null;
        }
        if (null === $block->slotForOperand($prev->result)) {
            foreach ($this->compileExpr($prev, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($prev->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * strlen(($q = pack(...))) — php-cfg dead arg temp vs assign.result (#11365, ext/standard/pack.c).
     */
    private function resolveAdjacentAssignExprCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        return $this->resolveAssignInCallRhsCallArgSlot($block, $cfgCallOp, $argIndex);
    }

    /**
     * Hoisted assign-in-call before a by-ref builtin — wire the RHS value, not the named lvalue (#15151).
     *
     * `array_multisort([..], $labels = [..])` is lowered as Array_, Array_, Assign, FuncCall; Zend couples
     * sort using the literal arrays but does not write sorted order back through assign-in-call operands.
     */
    private function resolveAssignInCallRhsCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?Operand $arg = null
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (
            !$this->callArgIsDeadInlineTemporary($callArg)
            && !$this->callArgIsAssignInCallOperand($callArg)
        ) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prev instanceof Op\Expr\Assign || null === $prev->result) {
            return null;
        }
        if (
            0 === $argIndex
            && !$this->callArgIsAssignInCallOperand($callArg)
            && !$this->operandsReferToSameVariable($callArg, $prev->result)
            && !$this->isAssignInCallFromPrecedingProducer($block, $prev)
        ) {
            return null;
        }
        $rhsExpr = $prev->expr;
        if (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseOr
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        } elseif (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseAnd
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        } elseif (
            $callIndex > 1
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $rhsExpr = $block->orig->children[$callIndex - 2];
        }
        if ($rhsExpr instanceof Operand) {
            $rhsOperand = $rhsExpr;
        } elseif (property_exists($rhsExpr, 'result') && $rhsExpr->result instanceof Operand) {
            $rhsOperand = $rhsExpr->result;
        } else {
            $slot = $this->slotForEmittedAssignRhsSlot($block, $prev);

            return null !== $slot ? (string) $slot : null;
        }
        if (null === $block->slotForOperand($rhsOperand)) {
            if ($rhsExpr instanceof Op) {
                foreach ($this->compileExpr($rhsExpr, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
        }
        $slot = $block->slotForOperand($rhsOperand);
        if (null === $slot) {
            $slot = $this->slotForEmittedAssignRhsSlot($block, $prev);
        }
        if (null === $slot) {
            return null;
        }

        return (string) $slot;
    }

    /**
     * `strlen(($q = pack(...)))` — assign.expr references the FuncCall sibling immediately before Assign (#16273, re-#11365).
     */
    private function isAssignInCallFromPrecedingProducer(Block $block, Op\Expr\Assign $assign): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $assignIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $assign) {
                $assignIndex = $i;
                break;
            }
        }
        if (null === $assignIndex || $assignIndex < 1) {
            return false;
        }
        $before = $block->orig->children[$assignIndex - 1] ?? null;
        if (
            !$before instanceof Op\Expr\FuncCall
            && !$before instanceof Op\Expr\NsFuncCall
            && !$before instanceof Op\Expr\StaticCall
            && !$before instanceof Op\Expr\MethodCall
            && !$before instanceof Op\Expr\New_
        ) {
            return false;
        }
        if (null === $before->result) {
            return false;
        }

        return $this->operandsReferToSameVariable($assign->expr, $before->result);
    }

    /** TYPE_ASSIGN arg3 for a registered assign.expr temp (#15151). */
    private function slotForEmittedAssignRhsSlot(Block $block, Op\Expr\Assign $assign): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $assignOrdinal = 0;
        $targetOrdinal = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Assign) {
                if ($child === $assign) {
                    $targetOrdinal = $assignOrdinal;
                    break;
                }
                ++$assignOrdinal;
            }
        }
        if (null === $targetOrdinal) {
            return null;
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (int) $op->arg3;
            }
            ++$seen;
        }

        return null;
    }

    private function isAdjacentNestedFuncCallProducer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex
    ): bool {
        if ($producerIndex !== $consumerIndex - 1) {
            return false;
        }
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
            && !$producer instanceof Op\Expr\StaticCall
            && !$producer instanceof Op\Expr\MethodCall
        ) {
            return false;
        }
        if (
            !$consumer instanceof Op\Expr\FuncCall
            && !$consumer instanceof Op\Expr\NsFuncCall
            && !$consumer instanceof Op\Expr\MethodCall
            && !$consumer instanceof Op\Expr\StaticCall
            && !$consumer instanceof Op\Expr\New_
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args) || [] === $consumer->args) {
            return false;
        }
        $args = $consumer->args;
        if (1 === count($args)) {
            return $this->callArgIsDeadInlineTemporary($args[0] ?? null);
        }
        // php-cfg `f(g(), literal)` — adjacent producer feeds arg0 (#10402, levenshtein(str_repeat(...), 'b')).
        // php-cfg `f($named, g())` — producer feeds last arg (#11409, chown($path, getmyuid())).
        // php-cfg `f('label', g(), 0)` — producer feeds a middle dead inline temp (#13451, #13450).
        foreach ($args as $arg) {
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                return true;
            }
        }

        return false;
    }

    private function nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\ArrayDimFetch) {
                // show(id($t), $t[0]) — dim is a sibling arg prelude, not a barrier (#36380).
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            if ($mid instanceof Op\Expr\FuncCall || $mid instanceof Op\Expr\NsFuncCall) {
                return false;
            }

            return false;
        }

        return true;
    }

    /**
     * Lone hoisted FuncCall before a multi-arg consumer with only ArrayDimFetch (and optional
     * scalar/unary) preludes between them — needs EXEC_RETURN (#36380).
     *
     * @param list<Op> $cfgChildren
     */
    private function nestedFuncCallProducerSeparatedByDimFetchPreludesOnly(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        $sawDim = false;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ArrayDimFetch) {
                $sawDim = true;
                continue;
            }
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }

            return false;
        }

        return $sawDim;
    }

    /**
     * tempnam(sys_get_temp_dir(), E::A) — nested FuncCall feeds arg #0; trailing enum is arg #1 (#10303, #16558).
     */
    private function nestedFuncCallFeedsDeadInlineCallArgZero(Block $block, Op $callOp, int $argIndex): bool
    {
        if (0 !== $argIndex || null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (null === $callIndex || $callIndex < 2) {
            return false;
        }
        $priorProducer = $block->orig->children[$callIndex - 2] ?? null;
        // var_export(require_once $f, true) — Include_/Eval_ + hoisted true (#25852).
        if (
            ($priorProducer instanceof Op\Expr\Include_ || $priorProducer instanceof Op\Expr\Eval_)
            && $this->isHoistedScalarConstFetchImmediatelyBeforeCall(
                $block->orig->children[$callIndex - 1] ?? null
            )
            && 2 === \count($callOp->args ?? [])
        ) {
            return true;
        }
        if (
            !($priorProducer instanceof Op\Expr\FuncCall || $priorProducer instanceof Op\Expr\NsFuncCall)
            || !$this->nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
                $callIndex - 2,
                $callIndex,
                $block->orig->children
            )
        ) {
            return false;
        }

        return 2 === \count($callOp->args ?? []);
    }

    /**
     * unpack('i', pack(...), E::A) — nested FuncCall feeds a middle dead-temp arg, not enum (#8866).
     */
    private function nestedFuncCallFeedsDeadInlineCallArg(Block $block, Op $callOp, int $argIndex): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (null === $callIndex) {
            return false;
        }
        $nested = $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
            $callOp,
            $callIndex,
            $block->orig->children
        );
        if (
            !($nested instanceof Op\Expr\FuncCall || $nested instanceof Op\Expr\NsFuncCall)
        ) {
            return false;
        }
        $nestedIndex = array_search($nested, $block->orig->children, true);
        if (!\is_int($nestedIndex)) {
            return false;
        }
        $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $nestedIndex,
            $callIndex,
            $block->orig->children
        );

        return null !== $targetArg && $targetArg === $argIndex;
    }

    /** Stmt-level side-effect builtins — not hoisted multi-arg producers (#16451, #16480). */
    private function isStatementLevelSideEffectFuncCall(Op\Expr $call): bool
    {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');

        return \in_array(
            $name,
            [
                'chmod',
                'chown',
                'chgrp',
                'unlink',
                'touch',
                'mkdir',
                'rmdir',
                'rename',
                'copy',
                'fwrite',
                'fputs',
                'ftruncate',
                // Stream position mutators — not hoisted arg producers for var_export/print_r (#25084, #16254).
                'rewind',
                'fseek',
                'fsetpos',
                'define',
                'date_sunrise',
                'date_sunset',
            ],
            true
        );
    }

    /**
     * Hoisted call-arg producers with PROFILE≥8.4 soft-null deprecation on a null literal.
     *
     * php-cfg may hoist json_decode(null) ahead of set_error_handler(); defer to the consumer
     * so user handlers observe E_DEPRECATED (Zend stmt order, #21223).
     */
    private function funcCallSoftNullDeprecationOnNullMustDeferAtConsumer(Op\Expr $call): bool
    {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            return false;
        }
        $first = $call->args[0] ?? null;
        if (!$this->callArgIsNullLiteral($first)) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
        if ('' === $name || !$this->funcCallNameMaySoftNullDeprecateOnProfile84($name)) {
            return false;
        }
        $first = $call->args[0] ?? null;
        if (!$this->callArgIsNullLiteral($first)) {
            return false;
        }

        return true;
    }

    /** PROFILE≥8.4 builtins that emit E_DEPRECATED on null → '' coercion (VmString trim-family). */
    private function funcCallNameMaySoftNullDeprecateOnProfile84(string $name): bool
    {
        if (!version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            return false;
        }

        return \in_array($name, [
            'json_decode',
            'json_validate',
            'unserialize',
            'trim',
            'ltrim',
            'rtrim',
            'chop',
            'strlen',
            'strtolower',
            'strtoupper',
            'strrev',
            'md5',
            'sha1',
            'hash',
            'hash_hmac',
            'base64_encode',
            'base64_decode',
            'parse_url',
            'htmlspecialchars',
            'htmlentities',
        ], true);
    }

    /**
     * Generator/Iterator resume methods — stmt-level side effects, not hoisted fwrite/var_dump arg producers (#16609, re-#13989).
     */
    private function methodCallHasStatementLevelSideEffects(Op\Expr\MethodCall $call): bool
    {
        $method = $this->staticNameFromOperand($call->name);
        if (null === $method) {
            return false;
        }

        return \in_array(strtolower($method), [
            'next',
            'send',
            'rewind',
            'throw',
        ], true);
    }

    /**
     * Iterator pointer stmts ($it->next()) before a hoisted sibling call-arg producer — not part of the chain (#13901, #17251).
     */
    private function siblingInlineCallProducerSkipsHoistedArgChain(Op $child, ?Op $nextChild = null): bool
    {
        if (
            $child instanceof Op\Expr\MethodCall
            && $this->methodCallHasStatementLevelSideEffects($child)
            && (
                $nextChild instanceof Op\Expr\FuncCall
                || $nextChild instanceof Op\Expr\NsFuncCall
                || $nextChild instanceof Op\Expr\MethodCall
                || $nextChild instanceof Op\Expr\StaticCall
            )
        ) {
            return true;
        }
        if (
            ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
            && $this->isArrayInternalPointerMutatorFuncName($this->resolveCfgFuncCallName($child))
            && (
                $nextChild instanceof Op\Expr\FuncCall
                || $nextChild instanceof Op\Expr\NsFuncCall
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Stmt-level iterator/generator pointer advance before a sibling MethodCall inline arg (#17251, #13901).
     *
     * php-cfg: `$it->next(); var_export($it->current(), true)` hoists both MethodCalls; only current feeds arg #0.
     */
    private function methodCallIsStmtLevelDiscardPrelude(Op\Expr\MethodCall $call): bool
    {
        if (!$this->methodCallHasStatementLevelSideEffects($call)) {
            return false;
        }
        if (!property_exists($call, 'result')) {
            return false;
        }

        return empty($call->result->usages);
    }

    /**
     * chmod(); substr(sprintf('%o', fileperms($path)), -N) — run stmt-level side effects before hoisted producers (#16480).
     *
     * @param list<Op> $cfgChildren
     */
    private function ensureStatementLevelSideEffectsBeforeChainStartCompiled(
        Block $block,
        int $chainStartIndex,
        array $cfgChildren
    ): void {
        if ($chainStartIndex <= 0) {
            return;
        }
        for ($k = 0; $k < $chainStartIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                !($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                || !$this->isStatementLevelSideEffectFuncCall($stmt)
            ) {
                continue;
            }
            if ($this->emittedFuncCallOpcodesForCfgStmt($block, $stmt)) {
                continue;
            }
            foreach ($this->compileExpr($stmt, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    /**
     * @param Op\Expr\FuncCall|Op\Expr\NsFuncCall $call
     */
    private function emittedFuncCallOpcodesForCfgStmt(Block $block, Op\Expr $call): bool
    {
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
        if ('' === $name) {
            return false;
        }
        $defineName = null;
        if ('define' === $name) {
            $arg0 = $call->args[0] ?? null;
            if ($arg0 instanceof Operand) {
                $lit = $this->staticNameFromOperand($arg0);
                if (null !== $lit) {
                    $defineName = strtolower($lit);
                }
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
                if ($name === $initName) {
                    return true;
                }
                continue;
            }
            // define('LIT', …) lowers to TYPE_DECLARE_GLOBAL_CONST with no FUNCCALL_INIT (#204).
            // Side-effect replay before hoisted var_export/defined() must not re-emit it (#32039).
            if (
                'define' === $name
                && OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type
            ) {
                if (null === $defineName) {
                    return true;
                }
                $declared = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
                if ($declared === $defineName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function ensureSideEffectsBeforeSubstrNestedSprintfCompiled(
        Block $block,
        int $callIndex,
        array $cfgChildren
    ): void {
        for ($k = 0; $k < $callIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                !($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                || !$this->isStatementLevelSideEffectFuncCall($stmt)
            ) {
                continue;
            }
            if ($this->emittedFuncCallOpcodesForCfgStmt($block, $stmt)) {
                continue;
            }
            foreach ($this->compileExpr($stmt, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    private function isNamedVariableOperand(Operand $arg): bool
    {
        $name = Block::resolveVariableName($arg);
        if (null !== $name && '' !== $name) {
            return true;
        }

        return $arg instanceof Operand\Variable
            && $arg->name instanceof Operand\Literal
            && is_string($arg->name->value)
            && '' !== $arg->name->value;
    }

    /**
     * Empty-usages createElement/appendChild (etc.) before PropertyFetch/ConstFetch + consumer are
     * prior statements, not importNode/replaceChild inline args.
     *
     * @param list<Op> $cfgChildren
     */
    private function emptyUsagesDomMutationIsPriorStatementBeforeConsumer(
        Op\Expr\MethodCall $child,
        int $childIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $method = strtolower($this->staticNameFromOperand($child->name) ?? '');
        if (!\in_array($method, [
            'appendchild',
            'insertbefore',
            'replacechild',
            'removechild',
            'append',
            'prepend',
            'createelement',
            'createelementns',
            'createtextnode',
            'createcomment',
        ], true)) {
            return false;
        }
        for ($j = $childIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (
                $mid instanceof Op\Expr\PropertyFetch
                || $mid instanceof Op\Expr\NullsafePropertyFetch
            ) {
                // `$el->childNodes->item(N)` — PropertyFetch feeds item(), not a prior
                // statement separator. Skipping createElement here made both ARG_SENDs
                // bind item() (#34436 / peer #34405 statement skip).
                if ($this->propertyFetchFeedsCallProducerBeforeConsumer(
                    $mid,
                    $j,
                    $consumerIndex,
                    $cfgChildren
                )) {
                    continue;
                }

                return true;
            }
            if (
                $mid instanceof Op\Expr\ConstFetch
                || $mid instanceof Op\Expr\ClassConstFetch
            ) {
                return true;
            }
        }

        return false;
    }

    /** Whether this MethodCall's METHODCALL_INIT was already lowered onto $block. */
    private function emittedMethodCallOpcodesForCfgStmt(Block $block, Op\Expr\MethodCall $call): bool
    {
        $method = strtolower($this->staticNameFromOperand($call->name) ?? '');
        if ('' === $method) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type) {
                continue;
            }
            $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg2, $block) ?? '');
            // METHODCALL_INIT arg2 is the method name slot; also try arg1 when layout differs.
            if ('' === $initName) {
                $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
            }
            if ($method === $initName) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg dead multi-arg temps with no dataflow to hoisted producers (#9463, #9351).
     *
     * @param list<Operand> $callArgs
     */
    private function callArgsAreDistinctInlineTemporaries(array $callArgs): bool
    {
        if (count($callArgs) < 2) {
            return false;
        }
        foreach ($callArgs as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dead inline temps among hoisted call args only — trailing embedded literals allowed (#18613).
     *
     * file_get_contents('data://text/plain,'.$p, false, null, 3, 4) hoists Concat + ConstFetch siblings.
     *
     * @param list<Operand> $callArgs
     */
    private function hoistedCallArgsAreDistinctInlineTemporaries(array $callArgs): bool
    {
        $hoistedCount = 0;
        foreach ($callArgs as $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
            ++$hoistedCount;
        }

        return $hoistedCount >= 2;
    }

    /**
     * Assign-in-arg Array_ is compiled via findInlineArrayProducerForCallArg but omitted from hoisted producers (#15154).
     *
     * @param list<Op\Expr> $producers
     */
    private function callArgUsesInlineArrayNotInHoistedProducers(
        Operand $arg,
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $producers
    ): bool {
        if (!$this->callArgIsDeadInlineTemporary($arg) || !$this->callArgOperandExpectsArrayProducer($arg)) {
            return false;
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                continue;
            }
            $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
            if (
                null !== $producer->result
                && (
                    $this->operandsReferToSameVariable($producer->result, $callArg)
                    || $this->operandsReferToSameVariable($producer->result, $arg)
                )
            ) {
                return false;
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
            if ($child instanceof Op\Expr\BinaryOp\Plus) {
                return false;
            }
            if ($child instanceof Op\Expr\Array_) {
                return !\in_array($child, $producers, true);
            }
            if ($child instanceof Op\Expr\Assign) {
                $prior = $block->orig->children[$i - 1] ?? null;
                if ($prior instanceof Op\Expr\Array_) {
                    return !\in_array($prior, $producers, true);
                }
                break;
            }
        }

        return false;
    }

}
