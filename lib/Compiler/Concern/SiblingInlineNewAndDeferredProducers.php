<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Sibling multi-arg New_/deferred FuncCall producer compile (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineFuncCallProducers} so gen-0 split-TU can
 * hollow a smaller Concern TU (hasSiblingMultiArgInlineCallProducers through
 * ensureDeferredSiblingInlineCallArgProducersCompiled, including New_ sibling
 * matchers and scalar ConstFetch prelude helpers).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineFuncCallProducers).
 */
trait SiblingInlineNewAndDeferredProducers
{
    /** True when php-cfg hoisted ≥2 sibling FuncCall producers before a multi-arg consumer (#13671). */
    private function hasSiblingMultiArgInlineCallProducers(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        if (\count($cfgCallOp->args) < 2) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndexImpl($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return false;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — fetches cover args; not a call-producer chain (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return false;
        }

        return ($callIndex - $firstSibling) >= 2;
    }

    /**
     * True when StaticPropertyFetch/PropertyFetch/ArrayDimFetch between sibling call producers
     * and the consumer can fill every dead-temp call arg (#34997).
     *
     * @param list<Op> $cfgChildren
     */
    private function interveningFetchProducersCoverDeadTempCallArgs(
        int $firstSibling,
        int $callIndex,
        array $cfgChildren,
        Op $cfgCallOp
    ): bool {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgCallOp);
        if ($deadInlineArgCount < 2) {
            return false;
        }
        $interveningFetchProducers = 0;
        for ($j = $firstSibling; $j < $callIndex; ++$j) {
            $between = $cfgChildren[$j] ?? null;
            if (
                $between instanceof Op\Expr\StaticPropertyFetch
                || $between instanceof Op\Expr\PropertyFetch
                || $between instanceof Op\Expr\NullsafePropertyFetch
                || $between instanceof Op\Expr\ArrayDimFetch
            ) {
                ++$interveningFetchProducers;
            }
        }

        return $interveningFetchProducers >= $deadInlineArgCount;
    }

    /**
     * True when php-cfg hoisted ≥2 sibling inline New_ producers before a multi-arg ctor (#17524, re-#15124).
     * Trailing ClassConstFetch/ConstFetch/scalar option preludes are skipped (#19731, #19738).
     */
    private function hasSiblingMultiArgInlineNewProducers(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        $args = $cfgCallOp->args;
        if (\count($args) < 2) {
            return false;
        }
        $siblingNews = $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp);
        $newCount = \count($siblingNews);

        return $newCount >= 2 && $newCount <= \count($args);
    }

    /**
     * Positional sibling inline New_ → multi-arg ctor/call (#17524, re-#15124 / #14483).
     * Allows trailing ClassConstFetch/ConstFetch/scalar options after New_ producers (#19731, #19738).
     * Nested `new Outer(new Mid(new Inner), Mode::C)` must not map Mid/Inner onto the mode
     * arg or Outer arg #0 by ordinal alone — require the New_ to feed that call arg (#22007, #19770).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand|null> $callArgs
     */
    private function matchSiblingInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        $producerCount = \count($producers);
        $argCount = \count($callArgs);
        if ($producerCount < 2) {
            return null;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\New_) {
                return null;
            }
        }
        if ($producerCount < $argCount) {
            // new DatePeriod(new DateTime(...), new DateInterval(...), new DateTime(...), INCLUDE_END_DATE)
            for ($i = $producerCount; $i < $argCount; ++$i) {
                if ($this->callArgIsNewExpression($callArgs[$i] ?? null)) {
                    return null;
                }
            }
        } elseif ($producerCount !== $argCount) {
            return null;
        }
        if ($argIndex >= $producerCount) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $matched = $producers[$argIndex] ?? null;
        if (!$matched instanceof Op\Expr\New_) {
            return null;
        }
        // Nested New_ chain (ParentIterator(RecursiveArrayIterator)) ≠ parallel sibling args (#22007).
        return $this->inlineNewProducerFeedsCallArg($matched, $callArg) ? $matched : null;
    }

    /**
     * Sibling inline New_ stmts immediately before $cfgCallOp (#17524).
     * Skips trailing ClassConstFetch/ConstFetch and scalar/flag option preludes
     * (#19731, #19735, #19738) — bitwise, arithmetic, unary, and casts.
     *
     * @return list<Op\Expr\New_>
     */
    private function siblingInlineNewProducersBeforeCfgOp(Block $block, Op $cfgCallOp): array
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex) {
            return [];
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= 0 && \count($producers) < \count($cfgCallOp->args); --$i) {
            $child = $block->orig->children[$i] ?? null;
            if ($this->isTrailingInlineNewCtorOptionPrelude($child)) {
                continue;
            }
            if (!$child instanceof Op\Expr\New_) {
                break;
            }
            array_unshift($producers, $child);
        }

        return $producers;
    }

    /**
     * Scalar / flag option prelude between sibling New_ args and outer ctor (#19731, #19738).
     * e.g. DatePeriod::INCLUDE_END_DATE, EXCLUDE_START_DATE|INCLUDE_END_DATE, or 1+2 / 1<<2 / -1.
     */
    private function isTrailingInlineNewCtorOptionPrelude(?Op $child): bool
    {
        if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
            return true;
        }
        if (
            $child instanceof Op\Expr\UnaryMinus
            || $child instanceof Op\Expr\UnaryPlus
            || $child instanceof Op\Expr\BitwiseNot
            || $child instanceof Op\Expr\Cast
        ) {
            return true;
        }

        return $this->isArithmeticInlineCallArgProducer($child);
    }

    private function cfgCallOpIndex(Block $block, Op $cfgCallOp): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (\is_int($callIndex)) {
            return $callIndex;
        }
        if (!property_exists($cfgCallOp, 'result') || null === $cfgCallOp->result) {
            return null;
        }
        foreach ($block->orig->children as $i => $child) {
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && null !== $child->result
                && (
                    $child->result === $cfgCallOp->result
                    || $this->operandsReferToSameVariable($child->result, $cfgCallOp->result)
                )
            ) {
                return $i;
            }
        }

        return null;
    }

    /**
     * var_dump(f(), g()) at cfg index — hoisted sibling consumer, not an EXEC_RETURN emitter (#16254).
     *
     * @param list<Op> $cfgChildren
     */
    private function isMultiArgSiblingInlineCallConsumerAt(int $callIndex, array $cfgChildren): bool
    {
        $consumer = $cfgChildren[$callIndex] ?? null;
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $cfgChildren);
        if (null === $firstSibling) {
            return false;
        }

        return ($callIndex - $firstSibling) >= 2;
    }

    /** substr($s, 0, 2) hoisted sibling — trailing embedded literals add one EXEC_RETURN (#16254). */
    private function extraExecReturnOrdinalsForInlineFuncCallProducer(Op $child): int
    {
        if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
            return 0;
        }
        if (!\is_array($child->args ?? null)) {
            return 0;
        }
        foreach ($child->args as $i => $arg) {
            if ($i > 0 && $this->isEmbeddedCallLiteralArg($arg)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * True when every CFG child between $fromExclusive and $toExclusive is true/false/null ConstFetch
     * (or ClassConstFetch). Used for importNode(...->item(0), true) chain deferral (#25702).
     *
     * @param list<Op> $cfgChildren
     */
    private function onlyScalarConstFetchPreludesBetween(
        int $fromExclusive,
        int $toExclusive,
        array $cfgChildren
    ): bool {
        if ($fromExclusive + 1 >= $toExclusive) {
            // Adjacent — not a ConstFetch-prelude pattern (require at least one scalar) (#25702).
            return false;
        }
        $sawScalar = false;
        for ($k = $fromExclusive + 1; $k < $toExclusive; ++$k) {
            $mid = $cfgChildren[$k] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($mid->name);
                if (
                    null === $name
                    || !\in_array(strtolower($name), ['true', 'false', 'null'], true)
                ) {
                    return false;
                }
                $sawScalar = true;
                continue;
            }
            if ($mid instanceof Op\Expr\ClassConstFetch) {
                $sawScalar = true;
                continue;
            }

            return false;
        }

        return $sawScalar;
    }

    /**
     * Whether a MethodCall before trailing true/false/null ConstFetch actually feeds the multi-arg
     * consumer (live result usage or dead-temp call arg) — #25702 / #26458.
     *
     * Bare statement MethodCalls such as {@code $r->appendChild($a)} before
     * {@code $r->insertBefore($b, null)} share the ConstFetch-null CFG shape but must not be
     * treated as sibling arg producers (that deferred the append and dropped prior children).
     */
    private function methodCallFeedsMultiArgConsumerAcrossScalarConstFetch(
        Op\Expr\MethodCall $producer,
        Op $consumer
    ): bool {
        if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
            return false;
        }
        if (null === $producer->result) {
            return false;
        }
        if (!empty($producer->result->usages)) {
            foreach ($producer->result->usages as $usage) {
                if ($usage === $consumer) {
                    return true;
                }
            }
        }
        // Dead-temp leaf (importNode(...->item(0), true) — #25702). php-cfg may use distinct
        // result/arg temporaries, so also accept a non-scalar-ConstFetch dead temp when this
        // MethodCall is the leaf before the ConstFetch prelude. Skip true/false/null ConstFetch
        // temps — those are not feeds from a prior stmt MethodCall such as appendChild (#26458).
        $sawNonScalarDeadTemp = false;
        foreach ($consumer->args as $arg) {
            if (!$this->callArgIsDeadInlineTemporary($arg)) {
                continue;
            }
            if ($this->callArgTemporaryIsHoistedTrueFalseNullConstFetch($arg)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($producer->result, $arg)) {
                return true;
            }
            $sawNonScalarDeadTemp = true;
        }

        return $sawNonScalarDeadTemp && empty($producer->result->usages);
    }

    /** True when a call-arg Temporary is the hoisted true/false/null ConstFetch prelude (#26458). */
    private function callArgTemporaryIsHoistedTrueFalseNullConstFetch(?Operand $arg): bool
    {
        if (!$arg instanceof Operand\Temporary) {
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
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall stmts before the consumer (#9463, #10981).
     * Compile each producer once with its own EXEC_RETURN slot before ARG_SEND wiring.
     */
    private function ensureDeferredSiblingInlineCallArgProducersCompiled(Block $block, Op $cfgCallOp): void
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return;
        }
        $argCount = \count($cfgCallOp->args);
        $cfgChildren = $block->orig->children;
        $this->ensureCfgChildrenOpIndicesBuilt($cfgChildren, $block->orig);
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return;
        }
        if (
            $argCount < 2
            && !(
                1 === $argCount
                && $callIndex >= 2
                && ($cfgChildren[$callIndex - 2] ?? null) instanceof Op\Expr
                && $this->isIifeHoistedFuncCallArgProducer(
                    $cfgChildren[$callIndex - 2],
                    $cfgCallOp,
                    $callIndex - 2,
                    $callIndex,
                    $cfgChildren
                )
            )
        ) {
            return;
        }
        if ($this->isSubstrNestedSprintfUnaryMinusPattern($cfgCallOp, $callIndex, $cfgChildren)) {
            $this->ensureSideEffectsBeforeSubstrNestedSprintfCompiled($block, $callIndex, $cfgChildren);
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            if (
                1 === $argCount
                && $callIndex >= 2
            ) {
                $producerIndex = $callIndex - 2;
                $producer = $cfgChildren[$producerIndex] ?? null;
                if (
                    $producer instanceof Op\Expr
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $cfgChildren
                    )
                    && null === $block->slotForOperand($producer->result)
                ) {
                    $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
                        $block,
                        $producerIndex,
                        $cfgChildren
                    );
                    $emitOps = [];
                    $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                    $this->forceDeferredSiblingCallReturnSlot = true;
                    try {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $emitOps[] = $op;
                        }
                    } finally {
                        $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                    }
                    foreach ($emitOps as $op) {
                        $block->addOpCode($op);
                    }
                }
            }

            return;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — do not re-emit stmt StaticCalls (#34997).
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return;
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if ($siblingFuncCount < 2) {
            // json_decode(str_repeat(...), true, 512, JSON_THROW_ON_ERROR) — lone hoisted FuncCall (#12009, #15441).
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            foreach ($producers as $producer) {
                $isFuncProducer = $producer instanceof Op\Expr\FuncCall
                    || $producer instanceof Op\Expr\NsFuncCall;
                $isMethodProducer = $producer instanceof Op\Expr\MethodCall
                    || $producer instanceof Op\Expr\StaticCall;
                if (!$isFuncProducer && !$isMethodProducer) {
                    continue;
                }
                if ($isFuncProducer && 'define' === strtolower($this->resolveCfgFuncCallName($producer) ?? '')) {
                    continue;
                }
                if ($isFuncProducer && $this->funcCallExprHasByRefMutatingSideEffects($producer)) {
                    continue;
                }
                $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
                if (!is_int($producerIndex)) {
                    continue;
                }
                if (
                    !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    && !$this->isIifeHoistedFuncCallArgProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    && !$this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $cfgCallOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                ) {
                    continue;
                }
                if (null !== $block->slotForOperand($producer->result)) {
                    break;
                }
                $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                $emitOps = [];
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $this->forceDeferredSiblingCallReturnSlot = true;
                try {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $emitOps[] = $op;
                    }
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                }
                foreach ($emitOps as $op) {
                    $block->addOpCode($op);
                }
                break;
            }

            return;
        }
        if ($this->callHasOnlyTrailingHoistedScalarConstFetchArgs($cfgCallOp, $block)) {
            return;
        }
        $contiguousFirst = $this->firstContiguousSiblingMultiArgProducerIndex(
            $callIndex,
            $cfgCallOp,
            $block->orig->children
        );
        if (null === $contiguousFirst) {
            $contiguousFirst = $firstSibling;
        } elseif ($arrayPreludeChain) {
            // array_diff_assoc(array_keys(), array_keys()) — Array_ between siblings, not a chain break (#16418).
            $contiguousFirst = $firstSibling;
        }
        $cfgChildren = $block->orig->children;
        // Include dead-temp MethodCall producers (createElement) that precede the contiguous
        // sibling chain for multi-arg MethodCall consumers (#25563).
        if (null !== $contiguousFirst) {
            for ($j = $contiguousFirst - 1; $j >= 0; --$j) {
                $prior = $cfgChildren[$j] ?? null;
                if (
                    $prior instanceof Op\Expr\MethodCall
                    && $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
                        $prior,
                        $cfgChildren,
                        $j
                    )
                ) {
                    $contiguousFirst = $j;
                    continue;
                }
                break;
            }
        }
        $this->ensureStatementLevelSideEffectsBeforeChainStartCompiled(
            $block,
            $contiguousFirst,
            $cfgChildren
        );
        // O(1) via Block cache — was a full opCodes scan per multi-arg call (#36387).
        $execReturnCountAtChainStart = $block->funccallExecReturnCount();
        for ($j = $contiguousFirst; $j < $callIndex; ++$j) {
            $producer = $cfgChildren[$j] ?? null;
            if (!$producer instanceof Op\Expr) {
                continue;
            }
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && $this->funcCallExprHasByRefMutatingSideEffects($producer)
            ) {
                continue;
            }
            $isSib = $this->isSiblingMultiArgFuncCallProducer(
                $producer,
                $cfgCallOp,
                $j,
                $callIndex,
                $cfgChildren
            );
            $isCreate = $producer instanceof Op\Expr\MethodCall
                && $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
                    $producer,
                    $cfgChildren,
                    $j
                );
            // Leaf MethodCall before trailing true/false/null ConstFetch multi-arg consumer (#25702).
            // Must feed the consumer (live usage or dead-temp arg) — not prior stmts like
            // appendChild before insertBefore($x, null) (#26458).
            if (
                !$isSib
                && $producer instanceof Op\Expr\MethodCall
                && $this->onlyScalarConstFetchPreludesBetween($j, $callIndex, $cfgChildren)
                && $this->isSiblingMultiArgInlineCallConsumer($cfgCallOp)
                && $this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($producer, $cfgCallOp)
            ) {
                $isSib = true;
            }
            // Receiver MethodCall feeding a later MethodCall whose result feeds this consumer
            // (importNode(...->item(0), true) — #25702). Do not hoist getElements→item→appendChild()
            // statement chains ahead of a following multi-arg FuncCall (#25949, re-#23514/#25842).
            if (
                !$isSib
                && !$isCreate
                && $producer instanceof Op\Expr\MethodCall
                && null !== $producer->result
            ) {
                for ($k = $j + 1; $k < $callIndex; ++$k) {
                    $later = $cfgChildren[$k] ?? null;
                    if (
                        !($later instanceof Op\Expr\MethodCall)
                        || !property_exists($later, 'var')
                        || null === $later->var
                        || !$this->operandsReferToSameVariable($producer->result, $later->var)
                        || null === $later->result
                        || empty($later->result->usages)
                    ) {
                        continue;
                    }
                    foreach ($later->result->usages as $laterUsage) {
                        if ($laterUsage === $cfgCallOp) {
                            $isSib = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$isSib && !$isCreate) {
                continue;
            }
            $siblingOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $j,
                $contiguousFirst,
                $cfgChildren,
                $callIndex
            );
            $execReturnCountNow = $block->funccallExecReturnCount();
            if ($execReturnCountNow >= $execReturnCountAtChainStart + $siblingOrdinal + 1) {
                continue;
            }
            $emitOps = [];
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $emitOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
            foreach ($emitOps as $op) {
                $block->addOpCode($op);
            }
        }
    }
}
