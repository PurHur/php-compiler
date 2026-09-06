<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Hoisted multi-arg sibling FuncCall chain helpers (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineFuncCallProducers} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see siblingMultiArgFuncCallProducerTargetArgIndex}
 * through {@see firstContiguousHoistedFuncCallProducerForMultiArgConsumer}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineFuncCallProducers).
 */
trait HoistedMultiArgSiblingFuncCallChain
{
    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgFuncCallProducerTargetArgIndex(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $distance = $consumerIndex - $producerIndex;
        if ($distance < 1) {
            return null;
        }
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            1 === $distance
            && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && \is_array($consumer->args)
        ) {
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }
            // probe('label', g()) / probe('label', in_array(...)) — adjacent hoisted callee (#15846, #16013).
            if ($producerIndex === $consumerIndex - 1) {
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                $firstSiblingAdjacent = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
                $useAdjacentArgZero = true;
                if (null !== $firstSiblingAdjacent) {
                    $adjacentProducer = $cfgChildren[$producerIndex] ?? null;
                    if ($adjacentProducer instanceof Op\Expr) {
                        $outerOrdinalAdjacent = $this->outerSiblingInlineFuncCallProducerOrdinal(
                            $adjacentProducer,
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        );
                        if (null !== $outerOrdinalAdjacent && $outerOrdinalAdjacent > 0) {
                            // array_intersect(f(g()), f(g())) — trailing outer producer is not arg #0 (#15488).
                            $useAdjacentArgZero = false;
                        }
                    }
                }
                if (
                    $useAdjacentArgZero
                    && (
                        !$this->builtinUsesTrailingComparatorCallback($consumerName)
                        || null === $firstSiblingAdjacent
                        || $producerIndex <= $firstSiblingAdjacent
                    )
                    && (
                        null === $firstSiblingAdjacent
                        || $producerIndex === $firstSiblingAdjacent
                        || $this->countSiblingInlineFuncCallProducers(
                            $firstSiblingAdjacent,
                            $consumerIndex,
                            $cfgChildren
                        ) < 2
                    )
                ) {
                    return $leadingEmbedded;
                }
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $producerIndex === $firstSibling) {
                // probe('label', g()) — sole adjacent hoisted producer feeds first hoisted arg (#15846).
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                if (
                    1 === $distance
                    && \in_array($consumerName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                    && 2 === \count($consumer->args)
                ) {
                    for ($i = $producerIndex - 1; $i >= 0; --$i) {
                        $prev = $cfgChildren[$i] ?? null;
                        if ($prev instanceof Op\Expr\Array_) {
                            return 1;
                        }
                        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                            break;
                        }
                        if (
                            !$this->isUnaryInlineSiblingCallArgExpr($prev)
                            && !($prev instanceof Op\Expr\ConstFetch)
                            && !($prev instanceof Op\Expr\ClassConstFetch)
                        ) {
                            break;
                        }
                    }
                }

                return $leadingEmbedded;
            }
        }
        $mid = $cfgChildren[$producerIndex + 1] ?? null;
        if (2 === $distance && $this->isUnaryInlineSiblingCallArgExpr($mid)) {
            return 0;
        }
        // tempnam(sys_get_temp_dir(), E::A) — FuncCall arg #0, ClassConstFetch prelude (#10303, #9321).
        // in_array('x', g(), true) — embedded needle + FuncCall haystack + ConstFetch strict (#15612, #16013).
        if (
            2 === $distance
            && ($mid instanceof Op\Expr\ClassConstFetch || $mid instanceof Op\Expr\ConstFetch)
        ) {
            $producer = $cfgChildren[$producerIndex] ?? null;
            $priorSibling = $cfgChildren[$producerIndex - 1] ?? null;
            if (
                !($priorSibling instanceof Op\Expr\FuncCall || $priorSibling instanceof Op\Expr\NsFuncCall)
                || !$this->isSiblingInlineCallProducerExpr($priorSibling)
            ) {
                if (
                    ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                        || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
                    && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbedded = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbedded;
                            continue;
                        }
                        break;
                    }
                    if ($leadingEmbedded > 0) {
                        return $leadingEmbedded;
                    }
                }

                return 0;
            }
            // in_array(get_class(), get_declared_classes(), true) — use ordinal path, not haystack shortcut (#17882).
        }
        if ($this->firstSiblingInlineFuncCallProducerIndexActive) {
            // Reentrant siblingMultiArg during firstSibling scan — must not recurse into impl (#16012).
            $firstSiblingWhileActive = $this->scanFirstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null !== $firstSiblingWhileActive && $producerIndex >= $firstSiblingWhileActive && $producerIndex < $consumerIndex) {
                $ordinalWhileActive = $this->siblingFuncCallChainHasArrayPrelude(
                    $firstSiblingWhileActive,
                    $consumerIndex,
                    $cfgChildren
                )
                    ? $this->siblingInlineFuncCallProducerOrdinal(
                        $producerIndex,
                        $firstSiblingWhileActive,
                        $cfgChildren
                    )
                    : ($producerIndex - $firstSiblingWhileActive);
                $consumerNameWhileActive = $this->resolveCfgFuncCallName($consumer);
                if ($this->builtinUsesTrailingComparatorCallback($consumerNameWhileActive)) {
                    $callbackArgIndex = \count($consumer->args) - 1;
                    $funcArgIndex = 0;
                    foreach ($consumer->args as $i => $callArg) {
                        if ($i >= $callbackArgIndex) {
                            break;
                        }
                        if (
                            $this->isEmbeddedCallLiteralArg($callArg)
                            || $this->callArgIsDeadInlineTemporary($callArg)
                        ) {
                            if ($funcArgIndex === $ordinalWhileActive) {
                                return $i;
                            }
                            ++$funcArgIndex;
                        }
                    }
                }
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    $leadingEmbeddedWhileActive = 0;
                    foreach ($consumer->args as $arg) {
                        if ($this->isEmbeddedCallLiteralArg($arg)) {
                            ++$leadingEmbeddedWhileActive;
                            continue;
                        }
                        break;
                    }

                    return $leadingEmbeddedWhileActive + $ordinalWhileActive;
                }
            }

            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                // show(strtoupper(...), ['k'=>false]) — ConstFetch+Array_ must not yield distance-1 (#26367).
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            $arrayLiteralTarget = $this->soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
                $producerIndex,
                $consumerIndex,
                $cfgChildren,
                $consumer
            );
            if (null !== $arrayLiteralTarget) {
                return $arrayLiteralTarget;
            }

            return $distance - 1;
        }
        if ($producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }

        $ordinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren
        );
        if ($ordinal < 0) {
            return null;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        if ($producer instanceof Op\Expr) {
            $outerOrdinal = $this->outerSiblingInlineFuncCallProducerOrdinal(
                $producer,
                $firstSibling,
                $consumerIndex,
                $cfgChildren
            );
            if (null !== $outerOrdinal) {
                $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
                $hoistedArgCount = 0;
                if (
                    ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                    && property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                ) {
                    foreach ($consumer->args as $hoistedArgIndex => $callArg) {
                        if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $hoistedArgIndex, $callArg)) {
                                continue;
                            }
                            ++$hoistedArgCount;
                        }
                    }
                }
                if (\count($outer) === $hoistedArgCount && \count($outer) < $consumerIndex - $firstSibling) {
                    $ordinal = $outerOrdinal;
                }
            }
        }
        if (
            ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerName = $this->resolveCfgFuncCallName($consumer);
            if ($this->builtinUsesTrailingComparatorCallback($consumerName)) {
                $callbackArgIndex = \count($consumer->args) - 1;
                $funcArgIndex = 0;
                foreach ($consumer->args as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if (
                        $this->isEmbeddedCallLiteralArg($callArg)
                        || $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        if ($funcArgIndex === $ordinal) {
                            return $i;
                        }
                        ++$funcArgIndex;
                    }
                }
            }
            $leadingEmbedded = 0;
            foreach ($consumer->args as $arg) {
                if ($this->isEmbeddedCallLiteralArg($arg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }

            return $leadingEmbedded + $ordinal;
        }

        // MethodCall ChildNode: replaceWith($el, 'txt', $el2) — remap producer ordinal onto
        // non-embedded dead-temp args only when an embedded literal is present (#21901).
        // Scoped to ChildNode mutators so insertBefore($new, $list->item(1)) ordinal legacy is unchanged.
        if (
            (
                $consumer instanceof Op\Expr\MethodCall
                || $consumer instanceof Op\Expr\NullsafeMethodCall
            )
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
        ) {
            $consumerMethod = strtolower((string) ($this->staticNameFromOperand($consumer->name) ?? ''));
            if (
                \in_array($consumerMethod, ['replacewith', 'before', 'after', 'append', 'prepend'], true)
            ) {
                $nonEmbeddedDeadIndices = [];
                $hasEmbeddedLiteral = false;
                foreach ($consumer->args as $i => $callArg) {
                    if ($this->isEmbeddedCallLiteralArg($callArg)) {
                        $hasEmbeddedLiteral = true;
                        continue;
                    }
                    if (
                        $callArg instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($callArg)
                    ) {
                        $nonEmbeddedDeadIndices[] = (int) $i;
                    }
                }
                if ($hasEmbeddedLiteral && isset($nonEmbeddedDeadIndices[$ordinal])) {
                    return $nonEmbeddedDeadIndices[$ordinal];
                }
            }
        }

        return $ordinal;
    }

    /**
     * chmod($path, …); substr(sprintf('%o', fileperms($path)), -N) — named-arg stmt call is not a hoisted producer (#16451, #16480).
     *
     * @param list<Op> $cfgChildren
     */
    private function stmtLevelNamedFuncCallPrecedesNestedInlineCallArgChain(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $producer = $cfgChildren[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!$this->funcCallHasNamedVariableOperand($producer)) {
            return false;
        }
        for ($k = $producerIndex + 1; $k < $consumerIndex - 1; ++$k) {
            $inner = $cfgChildren[$k] ?? null;
            $outer = $cfgChildren[$k + 1] ?? null;
            if (
                $inner instanceof Op\Expr
                && ($outer instanceof Op\Expr\FuncCall || $outer instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer($inner, $outer, $k, $k + 1)
            ) {
                return true;
            }
        }

        return false;
    }

    private function funcCallHasNamedVariableOperand(Op\Expr $call): bool
    {
        if (!property_exists($call, 'args') || !\is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if ($arg instanceof Operand && $this->isNamedVariableOperand($arg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statement-level FuncCall before a hoisted sibling chain feeding a multi-arg consumer (#13912, #13916).
     *
     * php-cfg `next($a); var_export(next($a), true)` hoists only the inner call — do not fold the
     * statement-level callee into {@see firstSiblingInlineFuncCallProducerIndex()}.
     *
     * Also true when only Array_/UnaryMinus preludes sit between a prior UDF call and the consumer
     * (`hold([]); array_pad([...], -N, 0)` — #15421).
     *
     * @param list<Op> $cfgChildren
     */
    private function producerIsHoistedMultiArgSiblingChainStart(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            !$consumer instanceof Op\Expr
            || !$this->isSiblingMultiArgInlineCallConsumer($consumer)
            || !\is_array($consumer->args ?? null)
            || \count($consumer->args) < 2
        ) {
            return false;
        }
        $deadArgs = [];
        foreach ($consumer->args as $arg) {
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                $deadArgs[] = $arg;
            }
        }
        // sprintf("…", count($a), $sum / count($a)) — leading format literal is not a dead temp;
        // compare against non-embedded arg count (#36353).
        $hoistedArgCount = 0;
        foreach ($consumer->args as $arg) {
            if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                ++$hoistedArgCount;
            }
        }
        if (\count($deadArgs) < 2 || \count($deadArgs) !== $hoistedArgCount) {
            return false;
        }
        if (!$this->callArgsAreDistinctInlineTemporaries($deadArgs)) {
            return false;
        }
        $funcProducerCount = 0;
        for ($j = $producerIndex; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (!($mid instanceof Op\Expr\FuncCall || $mid instanceof Op\Expr\NsFuncCall)) {
                continue;
            }
            // Prior show(strtoupper(...), [...]) statements are not value producers in this chain (#26367).
            if (
                \is_array($mid->args ?? null)
                && \count($mid->args) >= 2
                && $this->deadInlineTemporaryArgCount($mid) >= 2
                && (
                    null === ($cfgChildren[$consumerIndex] ?? null)
                    || !$this->inlineCallArgProducerFeedsConsumer($mid, $cfgChildren[$consumerIndex])
                )
            ) {
                continue;
            }
            ++$funcProducerCount;
        }

        return $funcProducerCount >= 2;
    }

    private function statementLevelFuncCallBeforeHoistedSiblingChain(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        $onlySkippablePreludes = true;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }
            if ($mid instanceof Op\Expr\ArrowFunction
                || $mid instanceof Op\Expr\Closure
                || $mid instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if ($mid instanceof Op\Expr\New_ || $mid instanceof Op\Expr\Clone_) {
                continue;
            }
            // take($r->lastChild, $d->createElement('y')) after prior take(...) — PropertyFetch
            // between statement call and next call is a hoisted arg, not a chain break (#19719).
            if (
                $mid instanceof Op\Expr\PropertyFetch
                || $mid instanceof Op\Expr\NullsafePropertyFetch
                || $mid instanceof Op\Expr\StaticPropertyFetch
            ) {
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($mid)) {
                if ($this->producerIsHoistedMultiArgSiblingChainStart($producerIndex, $consumerIndex, $cfgChildren)) {
                    return false;
                }
                // $e->getAttributeNode()->isId() before var_export(..., true) — receiver MethodCall
                // feeds the leaf; not a completed stmt ahead of the hoisted chain (#25841).
                $producer = $cfgChildren[$producerIndex] ?? null;
                if (
                    $producer instanceof Op\Expr\MethodCall
                    && $mid instanceof Op\Expr\MethodCall
                    && null !== $producer->result
                    && property_exists($mid, 'var')
                    && null !== $mid->var
                    && $this->operandsReferToSameVariable($producer->result, $mid->var)
                ) {
                    return false;
                }

                return true;
            }
            $onlySkippablePreludes = false;
            break;
        }
        if (!$onlySkippablePreludes) {
            return false;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $consumer instanceof Op\Expr
            && $this->producerFeedsConsumerArg0ThroughLiteralPreludesOnly(
                $producer,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )
        ) {
            // json_decode(g(), true, 512, JSON_THROW_ON_ERROR) — arg0 producer, not a stmt-level callee (#12009, #15441).
            return false;
        }
        if ($onlySkippablePreludes) {
            for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                $mid = $cfgChildren[$j] ?? null;
                if ($this->isSiblingInlineCallProducerExpr($mid)) {
                    if ($this->producerIsHoistedMultiArgSiblingChainStart($producerIndex, $consumerIndex, $cfgChildren)) {
                        return false;
                    }

                    return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
                }
            }

            // array_combine(array_keys(...), [...]) — trailing values Array_ means producer feeds arg #0 (#15949, re-#15857).
            if (
                ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && 'array_combine' === $this->resolveCfgFuncCallName($consumer)
            ) {
                for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                    if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                        return false;
                    }
                }
            }

            // var_dump(...); ini_get_all(null, false) — completed stmt callee, ConstFetch preludes only (#15931).
            // date_sun_info(strtotime(...), lat, -lon) still handled via producerFeedsConsumerArg0ThroughLiteralPreludesOnly above.
            // substr(sprintf(...), -N) — hoisted FuncCall arg #0 + UnaryMinus arg #1, not stmt-level (#10673, #16000).
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && \is_array($consumer->args ?? null)
                && \count($consumer->args) >= 2
                && $this->callArgIsDeadInlineTemporary($consumer->args[0] ?? null)
                && $this->callArgIsDeadInlineTemporary($consumer->args[1] ?? null)
            ) {
                $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                );
                if (0 === $targetArgIndex) {
                    $onlyUnaryPreludes = true;
                    for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                        $mid = $cfgChildren[$j] ?? null;
                        if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                            continue;
                        }
                        if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                            continue;
                        }
                        if ($mid instanceof Op\Expr\Array_) {
                            continue;
                        }
                        $onlyUnaryPreludes = false;
                        break;
                    }
                    if ($onlyUnaryPreludes) {
                        $hasArrayPreludeBetween = false;
                        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
                            if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                                $hasArrayPreludeBetween = true;
                                break;
                            }
                        }
                        if (!$hasArrayPreludeBetween) {
                            // substr(sprintf(...), -N) — UnaryMinus only, producer feeds arg #0 (#10673, #16000).
                            return false;
                        }
                        // hold([]); array_pad([...], -N, 0) — Array_ + UnaryMinus are consumer args (#15421, #16066).
                        if (
                            $producer instanceof Op\Expr
                            && (
                                $this->inlineCallArgProducerFeedsConsumer($producer, $consumer)
                                || $this->isNestedCallArgProducerForConsumer(
                                    $producer,
                                    $consumer,
                                    $producerIndex,
                                    $consumerIndex,
                                    $cfgChildren
                                )
                            )
                        ) {
                            return false;
                        }
                    }
                }
            }

            if (
                $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
            ) {
                return false;
            }

            return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
        }

        return $producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall;
    }

    /**
     * Hoisted FuncCall arg0 producer with only ConstFetch preludes before the consumer (#12009, #15441).
     *
     * @param list<Op> $cfgChildren
     */
    private function producerFeedsConsumerArg0ThroughLiteralPreludesOnly(
        Op\Expr $producer,
        Op $consumer,
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
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }

            return false;
        }
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || [] === $consumer->args) {
            return false;
        }
        $arg0 = $consumer->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($arg0)) {
            return false;
        }
        $hasEmbeddedMiddle = false;
        foreach ($consumer->args as $argIndex => $callArg) {
            if (0 === $argIndex) {
                continue;
            }
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                $hasEmbeddedMiddle = true;
                break;
            }
        }
        if (!$hasEmbeddedMiddle) {
            return false;
        }
        $literalPreludeCount = $consumerIndex - $producerIndex - 1;
        $hoistedArgCount = 0;
        foreach ($consumer->args as $callArg) {
            if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                ++$hoistedArgCount;
            }
        }

        return $literalPreludeCount === max(0, $hoistedArgCount - 1);
    }

    /**
     * Contiguous FuncCall stmts from {@see $fromIndex} feeding a distinct dead-temp consumer (#13969).
     *
     * @param list<Op> $cfgChildren
     */
    private function hasContiguousHoistedFuncCallProducersFrom(
        int $fromIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $consumer = $cfgChildren[$consumerIndex] ?? null;
        if (
            (null === $consumer || !$this->isSiblingMultiArgInlineCallConsumer($consumer))
            || !property_exists($consumer, 'args')
            || !is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return false;
        }
        $hoistedArgs = [];
        foreach ($consumer->args as $argIndex => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $callArg)) {
                continue;
            }
            $hoistedArgs[] = $callArg;
        }
        if (\count($hoistedArgs) < 2 || !$this->callArgsAreDistinctInlineTemporaries($hoistedArgs)) {
            return false;
        }
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                ($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                && $this->siblingInlineFuncCallSkipsExecReturnOrdinal($stmt, $k, $cfgChildren)
            ) {
                // var_dump(in_array(...)) between stmt chains — not one multi-arg hoisted consumer (#9390, #17317).
                return false;
            }
        }
        // chmod(); substr(sprintf('%o', fileperms($path)), -N) — stmt-level callee is not chain start (#16451).
        if (
            $this->statementLevelFuncCallBeforeHoistedSiblingChain($fromIndex, $consumerIndex, $cfgChildren)
        ) {
            $contiguousFirst = $this->firstContiguousSiblingMultiArgProducerIndex(
                $consumerIndex,
                $consumer,
                $cfgChildren
            );
            if (null === $contiguousFirst || $fromIndex !== $contiguousFirst) {
                return false;
            }
        }
        $outerProducerCount = \count(
            $this->outerSiblingInlineFuncCallProducers($fromIndex, $consumerIndex, $cfgChildren)
        );
        // array_intersect(f(g()), f(g())) — outer f() producers match hoisted arg temps (#15488, #16050, #16427).
        // in_array/array_search before var_dump — full scan for EXEC_RETURN ordinals (#9390, #17317).
        if ($outerProducerCount >= 2 && $outerProducerCount === \count($hoistedArgs)) {
            $consumerName = strtolower($this->resolveInlineCallArgFuncName($consumer) ?? '');
            if (!\in_array($consumerName, [
                'in_array',
                'array_search',
                'array_key_exists',
                'key_exists',
            ], true)) {
                return true;
            }
        }
        $arrayPreludeChain = $this->siblingFuncCallChainHasArrayPrelude(
            $fromIndex,
            $consumerIndex,
            $cfgChildren
        );
        $producerFuncCalls = 0;
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if ($stmt instanceof Op\Expr\ConstFetch || $stmt instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($stmt instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($stmt)) {
                continue;
            }
            // sprintf(..., count($a), $sum / count($a)) — Div between sibling counts (#36353).
            if ($stmt instanceof Op\Expr\BinaryOp) {
                continue;
            }
            if ($stmt instanceof Op\Expr\ArrowFunction
                || $stmt instanceof Op\Expr\Closure
                || $stmt instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            // var_dump(is_countable(null), is_countable(new ArrayObject())) — New_ between producers (#14958).
            if ($stmt instanceof Op\Expr\New_ || $stmt instanceof Op\Expr\Clone_) {
                continue;
            }
            if ($stmt instanceof Op\Expr\Assign || $stmt instanceof Op\Expr\AssignRef) {
                // $expected = [...] before array_udiff(array_keys(...), …) — not part of hoisted chain (#15475).
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($stmt)) {
                // array_intersect_assoc(array_keys([...]), array_keys([...])) — literal callees with
                // hoisted Array_ args (#13778, #13954). var_dump(acosh(1.5), …) / str_repeat('a', $n) (#14119, #10917).
                if (
                    !$this->funcCallExprUsesVariableCallee($stmt)
                    && !$arrayPreludeChain
                    && !$this->funcCallExprLiteralCalleeAllowedAsHoistedProducer($stmt)
                ) {
                    return false;
                }
                ++$producerFuncCalls;
                continue;
            }

            return false;
        }

        $hoistedArgCount = \count($hoistedArgs);
        $consumerName = $this->resolveInlineCallArgFuncName($consumer);
        if ($this->builtinUsesTrailingComparatorCallback($consumerName) && $hoistedArgCount > 1) {
            $callbackArg = $consumer->args[\count($consumer->args) - 1] ?? null;
            // array_udiff(g(), h(), 'strcmp') — embedded string callback is not a hoisted producer (#14021).
            if (null !== $callbackArg && !$this->isEmbeddedCallLiteralArg($callbackArg)) {
                --$hoistedArgCount;
            }
        }
        $lastProducerIndex = -1;
        for ($k = $fromIndex; $k < $consumerIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if ($this->isSiblingInlineCallProducerExpr($stmt)) {
                $lastProducerIndex = $k;
            }
        }
        if ($lastProducerIndex >= 0) {
            for ($k = $lastProducerIndex + 1; $k < $consumerIndex; ++$k) {
                $mid = $cfgChildren[$k] ?? null;
                if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                    --$hoistedArgCount;
                }
            }
        }

        $outerProducerCount = \count(
            $this->outerSiblingInlineFuncCallProducers($fromIndex, $consumerIndex, $cfgChildren)
        );
        if ($outerProducerCount >= 2 && $outerProducerCount === $hoistedArgCount) {
            return true;
        }

        return $producerFuncCalls >= 2 && $producerFuncCalls === $hoistedArgCount;
    }

    /** True when FuncCall callee is a variable/closure slot, not a literal name (#13969). */
    private function funcCallExprUsesVariableCallee(Op\Expr $expr): bool
    {
        if ($expr instanceof Op\Expr\FuncCall || $expr instanceof Op\Expr\NsFuncCall) {
            return !($expr->name instanceof Operand\Literal);
        }
        if ($expr instanceof Op\Expr\MethodCall || $expr instanceof Op\Expr\StaticCall) {
            return true;
        }

        return false;
    }

    /**
     * True when every FuncCall argument is an embedded php-cfg literal (acosh(1.5), str_repeat('a', 1)).
     */
    private function funcCallExprHasOnlyEmbeddedLiteralArgs(Op\Expr $expr): bool
    {
        if (!$expr instanceof Op\Expr\FuncCall && !$expr instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($expr, 'args') || !is_array($expr->args)) {
            return true;
        }
        foreach ($expr->args as $arg) {
            if (!$arg instanceof Operand\Literal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Literal-name callee allowed in a hoisted sibling producer chain (#14119, #10917).
     *
     * True for acosh(1.5) (embedded literals) and str_repeat('a', $n) (named variable temps).
     * False for array_keys($hoistedArrayTemp) where args are dead temps without a Variable root (#13778).
     */
    private function funcCallExprLiteralCalleeAllowedAsHoistedProducer(Op\Expr $expr): bool
    {
        if ($this->funcCallExprHasOnlyEmbeddedLiteralArgs($expr)) {
            return true;
        }
        if (!$expr instanceof Op\Expr\FuncCall && !$expr instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($expr, 'args') || !is_array($expr->args)) {
            return true;
        }
        foreach ($expr->args as $arg) {
            if ($arg instanceof Operand\Literal) {
                continue;
            }
            if ($arg instanceof Operand\Variable) {
                continue;
            }
            if ($arg instanceof Operand\Temporary && $arg->original instanceof Operand\Variable) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * var_dump(strlen($s), substr($s, 0, 2)) — N stmt-level FuncCalls before a multi-arg consumer (#16254).
     *
     * php-cfg hoists each arg producer as a sibling; statementLevelFuncCallBeforeHoistedSiblingChain would
     * otherwise trim strlen when substr sits between it and var_dump.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstContiguousHoistedFuncCallProducerForMultiArgConsumer(
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): ?int {
        if (
            !$this->isSiblingMultiArgInlineCallConsumer($consumer)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return null;
        }
        $hoistedArgs = [];
        foreach ($consumer->args as $argIndex => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($consumer, (int) $argIndex, $callArg)) {
                continue;
            }
            $hoistedArgs[] = $callArg;
        }
        if (
            \count($hoistedArgs) < 2
            || !$this->callArgsAreDistinctInlineTemporaries($hoistedArgs)
        ) {
            return null;
        }
        $hoistedArgCount = \count($hoistedArgs);
        $first = $consumerIndex - $hoistedArgCount;
        if ($first < 0) {
            return null;
        }
        for ($j = $first; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                continue;
            }
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if ($child instanceof Op\Expr\New_ || $child instanceof Op\Expr\Clone_) {
                continue;
            }
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                continue;
            }
            if (
                $child instanceof Op\Expr\PropertyFetch
                && $this->isPropertyFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\ArrayDimFetch
                && $this->isArrayDimFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\StaticPropertyFetch
                && $this->isStaticPropertyFetchOnlyIssetVar($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                return null;
            }
        }
        $outer = $this->outerSiblingInlineFuncCallProducers($first, $consumerIndex, $cfgChildren);
        if (\count($outer) !== $hoistedArgCount) {
            return null;
        }

        return $first;
    }
}
