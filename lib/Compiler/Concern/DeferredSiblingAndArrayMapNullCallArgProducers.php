<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Deferred sibling inline call-arg consumers + array_map null-callback producers (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Peer of SiblingInlineFuncCallAndDeadArrayProducers (#36713): covers the
 * deferredSiblingInlineCallArgConsumerIndex path, sibling FuncCall chain
 * array preludes, and array_map(null, …) haystack producers left in the hub.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait DeferredSiblingAndArrayMapNullCallArgProducers
{
    private function isInlineExprCallArgProducer(Op $op): bool
    {
        return $op instanceof Op\Expr\Array_
            || $op instanceof Op\Expr\ArrayDimFetch
            || $op instanceof Op\Expr\PropertyFetch
            || $op instanceof Op\Expr\StaticPropertyFetch
            || $op instanceof Op\Expr\BinaryOp
            || $op instanceof Op\Expr\New_
            || $op instanceof Op\Expr\ConstFetch
            || $op instanceof Op\Expr\ClassConstFetch
            || $op instanceof Op\Expr\Closure
            || $op instanceof Op\Expr\ArrowFunction
            || $op instanceof Op\Expr\FirstClassCallable
            || $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\NullsafePropertyFetch
            || $op instanceof Op\Expr\NullsafeMethodCall
            || $op instanceof Op\Expr\UnaryMinus
            || $op instanceof Op\Expr\UnaryPlus
            || $op instanceof Op\Expr\BitwiseNot
            || $op instanceof Op\Expr\BooleanNot
            || $op instanceof Op\Expr\Empty_
            || $op instanceof Op\Expr\Eval_
            || $op instanceof Op\Expr\Include_
            || $op instanceof Op\Expr\Isset_
            || $op instanceof Op\Expr\InstanceOf_
            || $op instanceof Op\Expr\In_
            || $op instanceof Op\Expr\Cast
            || $op instanceof Op\Expr\Clone_
            || $op instanceof Op\Expr\MagicScriptConst
            || $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\PostInc
            || $op instanceof Op\Expr\PreInc
            || $op instanceof Op\Expr\PostDec
            || $op instanceof Op\Expr\PreDec
            || $op instanceof Op\Expr\ConcatList;
    }

    /**
     * Multi-arg call after MethodCall with an ArrayDimFetch sibling between them (#28821).
     *
     * @param Op[] $ops
     */
    private function multiArgConsumerAfterMethodCallDimFetchSibling(array $ops, int $producerIndex): ?int
    {
        $opCount = \count($ops);
        $sawDimFetch = false;
        for ($j = $producerIndex + 1; $j < $opCount; ++$j) {
            $next = $ops[$j] ?? null;
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                $sawDimFetch = true;
                continue;
            }
            if (
                $next instanceof Op\Expr\FuncCall
                || $next instanceof Op\Expr\NsFuncCall
                || $next instanceof Op\Expr\MethodCall
                || $next instanceof Op\Expr\StaticCall
            ) {
                if (!$sawDimFetch) {
                    return null;
                }
                if (
                    !$this->isSiblingMultiArgInlineCallConsumer($next)
                    || !\is_array($next->args ?? null)
                    || \count($next->args) < 2
                    || $this->deadInlineTemporaryArgCount($next) < 2
                ) {
                    // Single-arg count() etc. — keep looking for var_dump/f(...).
                    if (
                        ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                        && (!\is_array($next->args ?? null) || \count($next->args) < 2)
                    ) {
                        continue;
                    }

                    return null;
                }
                // Distance must stay within dead-temp window (+ trailing FuncCall siblings).
                if (($j - $producerIndex) > $this->deadInlineTemporaryArgCount($next) + 1) {
                    return null;
                }

                return $j;
            }
            if (
                $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\NullsafePropertyFetch
                || $next instanceof Op\Expr\StaticPropertyFetch
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function deferredSiblingInlineCallArgConsumerIndex(Op $op, array $ops, int $producerIndex): ?int
    {
        $cacheKey = spl_object_id($op);
        if (\array_key_exists($cacheKey, $this->deferredSiblingInlineCallArgConsumerIndexCache)) {
            $cached = $this->deferredSiblingInlineCallArgConsumerIndexCache[$cacheKey];

            return $cached < 0 ? null : $cached;
        }
        $result = $this->computeDeferredSiblingInlineCallArgConsumerIndex($op, $ops, $producerIndex);
        $this->deferredSiblingInlineCallArgConsumerIndexCache[$cacheKey] = null === $result ? -1 : $result;

        return $result;
    }

    /**
     * Forward scan for the multi-arg consumer that should compile a hoisted sibling producer.
     *
     * Bound the walk: isSibling rejects pairs beyond max(8, arity×4), and scanning every later
     * FuncCall in a block of repeated nested call stmts was O(n²) PHP call overhead (#36387).
     *
     * @param Op[] $ops
     */
    private function computeDeferredSiblingInlineCallArgConsumerIndex(Op $op, array $ops, int $producerIndex): ?int
    {
        if (!$this->isSiblingInlineCallProducerExpr($op)) {
            return null;
        }
        if (
            ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprHasByRefMutatingSideEffects($op)
        ) {
            return null;
        }
        $opCount = \count($ops);
        // Hard cap past isSibling's practical maxDistance (arity×4); prelude-heavy nests stay inside.
        $scanLimit = min($opCount, $producerIndex + 1 + 32);
        for ($j = $producerIndex + 1; $j < $scanLimit; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                if ($this->isInlineExprCallArgConsumer($next)
                    && (
                        $this->isSiblingMultiArgFuncCallProducer($op, $next, $producerIndex, $j, $ops)
                        || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                            $op,
                            $next,
                            $producerIndex,
                            $j,
                            $ops
                        )
                        || (
                            $op instanceof Op\Expr
                            && $this->isIifeHoistedFuncCallArgProducer(
                                $op,
                                $next,
                                $producerIndex,
                                $j,
                                $ops
                            )
                        )
                        // var_export($e->getAttributeNode()->isId(), true) — leaf MethodCall before
                        // ConstFetch true; nestedSep ordinal can miss the distinct result/arg temp (#25841).
                        || (
                            $op instanceof Op\Expr\MethodCall
                            && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                            && property_exists($next, 'args')
                            && \is_array($next->args)
                            && \count($next->args) >= 2
                            && $this->callArgIsDeadInlineTemporary($next->args[0] ?? null)
                        )
                    )
                ) {
                    return $j;
                }

                // var_dump($g(), $g()) — keep scanning past 0/1-arg sibling producers toward the
                // multi-arg consumer. A non-matching ≥2-arg call is the next statement's nest
                // (or an unrelated consumer) — stop so nested stmt blocks stay O(n) (#36387).
                $nextArgCount = property_exists($next, 'args') && \is_array($next->args)
                    ? \count($next->args)
                    : 0;
                if ($nextArgCount >= 2) {
                    break;
                }
                continue;
            }
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                if ($this->isSiblingMultiArgFuncCallProducer($op, $next, $producerIndex, $j, $ops)) {
                    // ConstFetch-null/true/false between MethodCalls: only defer when the leaf feeds
                    // the consumer (importNode item+true — #25702). Skip stmt appendChild (#26458).
                    if (
                        $op instanceof Op\Expr\MethodCall
                        && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                        && !$this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($op, $next)
                    ) {
                        continue;
                    }
                    return $j;
                }
                // importNode(...->item(0), true) — only true/false/null ConstFetch between leaf MethodCall
                // and consumer; detect structurally (isNestedCallArg can fail under firstSibling reentry) (#25702).
                // Require the leaf to feed the consumer — bare stmt MethodCalls such as appendChild
                // before insertBefore($x, null) must not be deferred (#26458).
                if (
                    $op instanceof Op\Expr\MethodCall
                    && $this->isSiblingMultiArgInlineCallConsumer($next)
                    && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                    && $this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($op, $next)
                ) {
                    return $j;
                }
                // Non-matching multi-arg MethodCall/StaticCall ends the deferred-consumer search (#36387).
                $nextArgCount = property_exists($next, 'args') && \is_array($next->args)
                    ? \count($next->args)
                    : 0;
                if ($nextArgCount >= 2) {
                    break;
                }
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($next)) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($next)) {
                continue;
            }
            if ($next instanceof Op\Expr\Array_) {
                continue;
            }
            if ($next instanceof Op\Expr\ConstFetch || $next instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\New_ || $next instanceof Op\Expr\Clone_) {
                continue;
            }
            // var_dump($s->contains($o), $s[$o], count($s)) — dim is a sibling arg, not a chain end (#28821).
            // Do not skip PropertyFetch: saveXML($d->documentElement, LIBXML_*) must not treat a
            // prior stmt MethodCall (loadXML) as a deferred sibling across the property + LIBXML_*
            // preludes — that steals ARG_SEND and dumps the full document (re-#25292 / #29076).
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\ArrowFunction
                || $next instanceof Op\Expr\Closure
                || $next instanceof Op\Expr\FirstClassCallable) {
                // array_udiff(array_keys(...), array_keys(...), strcmp(...)) — trailing FCC (#15475, #13990).
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function countSiblingInlineFuncCallProducers(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): int {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $count = 0;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->isStatementLevelSideEffectFuncCall($child)
            ) {
                // Never count stmt-level side effects as hoisted arg producers (#25084, #16480).
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * True when php-cfg hoisted sibling Array_ literals between FuncCall producers (#13778).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingFuncCallChainHasArrayPrelude(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                return true;
            }
        }

        return false;
    }

    /**
     * 0-based ordinal among hoisted sibling FuncCall producers (skips Array_/ConstFetch between calls).
     *
     * @param list<Op> $cfgChildren
     */
    /**
     * Stmt-level var_dump(g()) — adjacent nested void consumer, no FUNCCALL_EXEC_RETURN slot (#9390).
     *
     * var_export(f()) still emits EXEC_RETURN and must stay in the ordinal map (#8796).
     *
     * @param list<Op> $cfgChildren
     */
    private function arrayMapNullCallbackPrecedesInlineHaystack(?Op $callOp, ?Block $block): bool
    {
        return null !== $callOp
            && null !== $block
            && 'array_map' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
            && null !== $this->arrayMapNullCallbackProducerBeforeCfgCall($callOp, $block);
    }

    /**
     * array_map(null, null, …) / array_map(null, [[..]]) — inline null ConstFetch preludes in CFG order (#9143, #16226).
     *
     * @return list<Op\Expr\ConstFetch>
     */
    private function arrayMapInlineNullConstFetchProducersBeforeCfgCall(Op $cfgCallOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $callbackArg = $cfgCallOp->args[0] ?? null;
        if ($this->isEmbeddedCallLiteralArg($callbackArg)) {
            return [];
        }
        $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
        if ($leadingCallback instanceof Op\Expr\ArrowFunction
            || $leadingCallback instanceof Op\Expr\Closure
            || $leadingCallback instanceof Op\Expr\FirstClassCallable) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return [];
        }
        $nulls = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch) {
                $constName = $this->staticNameFromOperand($child->name);
                if (null !== $constName && 'null' === strtolower($constName)) {
                    array_unshift($nulls, $child);
                    continue;
                }
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                break;
            }
        }

        return $nulls;
    }

    /**
     * array_map(null, [[..]]) — hoisted null ConstFetch precedes nested Array_ preludes (#9143).
     */
    private function arrayMapNullCallbackProducerBeforeCfgCall(Op $cfgCallOp, Block $block): ?Op\Expr\ConstFetch
    {
        $nulls = $this->arrayMapInlineNullConstFetchProducersBeforeCfgCall($cfgCallOp, $block);
        $first = $nulls[0] ?? null;

        return $first instanceof Op\Expr\ConstFetch ? $first : null;
    }

    /**
     * array_map(null, null, [..]) — inline null haystack operand, not zip Array_ (#16226).
     */
    private function arrayMapInlineNullHaystackProducerForArgIndex(
        Op $cfgCallOp,
        Block $block,
        int $argIndex
    ): ?Op\Expr\ConstFetch {
        if ($argIndex < 1 || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $nullProducers = $this->arrayMapInlineNullConstFetchProducersBeforeCfgCall($cfgCallOp, $block);
        if (\count($nullProducers) < 2) {
            return null;
        }
        $nullHaystackOrdinal = 0;
        for ($i = 1; $i < $argIndex; ++$i) {
            $prior = $cfgCallOp->args[$i] ?? null;
            if (!$prior instanceof Operand || $this->isEmbeddedCallLiteralArg($prior)) {
                continue;
            }
            if (!$this->callArgOperandExpectsArrayProducer($prior)) {
                ++$nullHaystackOrdinal;
            }
        }
        $targetIndex = 1 + $nullHaystackOrdinal;
        $candidate = $nullProducers[$targetIndex] ?? null;

        return $candidate instanceof Op\Expr\ConstFetch ? $candidate : null;
    }

/**
     * Nested inline producer compile may prepend FUNCCALL_INIT to $block early (#17697);
     * drain back into the producer chain so partitionNestedInlineCallArgProducerOps stays contiguous (#17862).
     *
     * @return list<OpCode>
     */
    private function drainBlockOpcodesAppendedSince(Block $block, int $sinceIndex): array
    {
        $drained = array_slice($block->opCodes, $sinceIndex);
        if ([] === $drained) {
            return [];
        }
        $block->opCodes = array_slice($block->opCodes, 0, $sinceIndex);
        $block->nOpCodes = $sinceIndex;
        $block->invalidateOpcodeDerivedIndexes();

        return $drained;
    }


    /**
     * var_export(g(), true) — nested callee before trailing ConstFetch preludes feeds arg #0 (#11272, #16298).
     * is_a(new C(), Parent::class) — inline New_ before trailing ::class feeds arg #0 (#17502).
     *
     * @param list<Op> $cfgChildren
     */
    private function nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
        Op $consumer,
        int $consumerIndex,
        array $cfgChildren
    ): ?Op\Expr {
        if ($consumerIndex < 1) {
            return null;
        }
        $probeIndex = $consumerIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $cfgChildren[$probeIndex] ?? null;
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            break;
        }
        $prev = $cfgChildren[$probeIndex] ?? null;
        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
            if (!$this->isNestedCallArgProducerForConsumer(
                $prev,
                $consumer,
                $probeIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return null;
            }
        } elseif ($prev instanceof Op\Expr\MethodCall || $prev instanceof Op\Expr\StaticCall) {
            if (!$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $prev,
                $consumer,
                $probeIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return null;
            }
        } elseif ($prev instanceof Op\Expr\New_) {
            if (
                !property_exists($consumer, 'args')
                || !\is_array($consumer->args)
                || \count($consumer->args) < 2
            ) {
                return null;
            }
            $callArg = $consumer->args[0] ?? null;
            if (
                !$this->callArgIsNewExpression($callArg)
                && (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg))
            ) {
                return null;
            }
        } else {
            return null;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $probeIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
        }

        return 0 === $targetArgIndex ? $prev : null;
    }

    /**
     * Single hoisted ArrowFunction/Closure with extra named call args (#9154, array_any/find family).
     *
     * php-cfg may emit `array_any($arr, fn ($v) => …)` as one closure producer plus a named
     * first argument — the closure must not be wired to arg 0.
     */
}
