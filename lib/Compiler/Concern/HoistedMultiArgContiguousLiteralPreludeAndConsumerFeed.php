<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Contiguous hoisted FuncCall / literal-prelude consumer-feed helpers (#36387).
 *
 * Extracted from {@see HoistedMultiArgSiblingFuncCallChain} so gen-0 split-TU can
 * hollow a smaller Concern TU (`producerFeedsConsumerArg0ThroughLiteralPreludesOnly`
 * through `firstContiguousHoistedFuncCallProducerForMultiArgConsumer`).
 *
 * Call sites and visibility stay identical — move-only. Mirrors php-src
 * Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait HoistedMultiArgContiguousLiteralPreludeAndConsumerFeed
{
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
