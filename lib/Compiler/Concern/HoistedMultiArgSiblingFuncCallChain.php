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
 * hollow a smaller Concern TU ({@see stmtLevelNamedFuncCallPrecedesNestedInlineCallArgChain}
 * through {@see statementLevelFuncCallBeforeHoistedSiblingChain}); target-arg-index
 * lives in {@see HoistedMultiArgSiblingProducerTargetArgIndex}; trailing
 * contiguous/literal-prelude helpers live in {@see HoistedMultiArgContiguousLiteralPreludeAndConsumerFeed}.
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

}
