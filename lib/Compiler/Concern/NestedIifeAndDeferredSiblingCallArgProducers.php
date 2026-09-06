<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * Nested / IIFE / deferred-sibling call-arg producer predicates (#36387 / prior #36147).
 *
 * Extracted from {@see PrecedingInlineCallArgProducers} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see isNestedCallArgProducerForConsumer} through
 * {@see isDeferredTrailingComparatorFirstClassCallable}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent / nested call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as PrecedingInlineCallArgProducers).
 */
trait NestedIifeAndDeferredSiblingCallArgProducers
{
    /**
     * php-cfg `f(g())` may lower to adjacent FuncCalls with distinct result/arg temporaries
     * (`strlen(trim($s))` → trim #6, strlen arg #7) (#8561, bootstrap-aot trim).
     *
     * Also `(fn($x) => ...)(g())` where php-cfg inserts the closure callee between nested calls (#8836).
     *
     * @param list<Op> $cfgChildren
     */
    private function isNestedCallArgProducerForConsumer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprHasByRefMutatingSideEffects($producer)
            && !$this->isArrayInternalPointerMutatorFuncName($this->resolveCfgFuncCallName($producer))
        ) {
            return false;
        }
        if ($this->isAdjacentNestedFuncCallProducer($producer, $consumer, $producerIndex, $consumerIndex)) {
            return true;
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            && property_exists($consumer, 'args')
            && is_array($consumer->args)
            && \count($consumer->args) >= 2
            && $this->nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )
        ) {
            // Stmt-level sibling with embedded haystack literal — not nested f(...), -N) (#17598).
            if ($this->isEmbeddedCallLiteralArg($consumer->args[0] ?? null)) {
                return false;
            }

            return true;
        }
        if ($producerIndex + 2 === $consumerIndex) {
            $mid = $cfgChildren[$producerIndex + 1] ?? null;
            if (
                $this->isUnaryInlineSiblingCallArgExpr($mid)
                && ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && property_exists($consumer, 'args')
                && is_array($consumer->args)
                && \count($consumer->args) >= 2
            ) {
                // substr(sprintf('%o', fileperms($path)), -N) — UnaryMinus offset between nested callee and consumer (#16451, #16480).
                if ($this->isEmbeddedCallLiteralArg($consumer->args[0] ?? null)) {
                    return false;
                }

                return true;
            }
            if (
                $mid instanceof Op\Expr\Array_
                && ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
                && property_exists($consumer, 'args')
                && is_array($consumer->args)
                && \count($consumer->args) >= 2
            ) {
                $nonEmbedded = 0;
                foreach ($consumer->args as $arg) {
                    if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                        ++$nonEmbedded;
                    }
                }
                // check('label', explode(..., -1), ['expect']) — FuncCall + hoisted Array_ (#13423, #13424).
                if ($nonEmbedded >= 2) {
                    return true;
                }
                // array_merge(array_keys($src), ['b']) — nested FuncCall + trailing embedded Array_ (#15551).
                $consumerName = $this->resolveCfgFuncCallName($consumer);
                if (
                    \in_array($consumerName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                    && $nonEmbedded >= 1
                ) {
                    return true;
                }
            }
        }
        if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
            $producer,
            $consumer,
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        )) {
            return true;
        }
        if ($producerIndex + 2 !== $consumerIndex) {
            return false;
        }
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (
            !$this->isSiblingMultiArgInlineCallConsumer($consumer)
        ) {
            return false;
        }
        $callee = $cfgChildren[$producerIndex + 1] ?? null;
        if (!$callee instanceof Op\Expr\ArrowFunction && !$callee instanceof Op\Expr\Closure) {
            return false;
        }
        if (!property_exists($consumer, 'name')) {
            return false;
        }

        return $consumer->name === $callee->result
            || $this->operandsReferToSameVariable($consumer->name, $callee->result);
    }

    /**
     * php-cfg `(function ($g) { … })($gen())` hoists `$gen()` two stmts before the IIFE __invoke (#10731).
     *
     * @param list<Op> $cfgChildren
     */
    private function isIifeHoistedFuncCallArgProducer(
        Op $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex + 2 !== $consumerIndex) {
            return false;
        }
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args) || 1 !== \count($consumer->args)) {
            return false;
        }
        $callee = $cfgChildren[$producerIndex + 1] ?? null;
        if (!$callee instanceof Op\Expr\Closure && !$callee instanceof Op\Expr\ArrowFunction) {
            return false;
        }
        if (!property_exists($consumer, 'name') || null === $callee->result) {
            return false;
        }
        if (
            $consumer->name !== $callee->result
            && !$this->operandsReferToSameVariable($consumer->name, $callee->result)
        ) {
            return false;
        }
        $callArg = $consumer->args[0] ?? null;
        if (
            null !== $callArg
            && !$this->callArgIsDeadInlineTemporary($callArg)
            && !$this->inlineCallArgProducerFeedsCallArgOp($producer, $consumer, $callArg)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Compile hoisted IIFE arg producer and return its result slot for TYPE_ARG_SEND (#10731).
     *
     * @param list<OpCode> $emitOps
     */
    private function resolveIifeHoistedFuncCallArgProducerSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (0 !== $argIndex || null === $block->orig) {
            return null;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args) || 1 !== \count($cfgCallOp->args)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $producerIndex = $callIndex - 2;
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (
            !$producer instanceof Op\Expr
            || !$this->isIifeHoistedFuncCallArgProducer(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $callIndex,
                $block->orig->children
            )
        ) {
            return null;
        }
        if (null !== $block->slotForOperand($producer->result)) {
            $existing = $block->slotForOperand($producer->result);

            return null !== $existing ? (string) $existing : null;
        }
        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
        $this->forceDeferredSiblingCallReturnSlot = true;
        try {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $emitOps[] = $op;
            }
        } finally {
            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
        }
        $slot = $block->slotForOperand($producer->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * php-cfg `var_export(g(), true)` hoists trailing literal ConstFetch between nested calls (#10495).
     *
     * @param list<Op> $cfgChildren
     */
    private function isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
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
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && 'define' === strtolower($this->resolveCfgFuncCallName($producer) ?? '')
        ) {
            return false;
        }
        // array_splice($a, …); json_encode($a, JSON_*) — by-ref stmt must not re-emit as producer (#13573).
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprHasByRefMutatingSideEffects($producer)
            && !$this->isArrayInternalPointerMutatorFuncName($this->resolveCfgFuncCallName($producer))
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
        if (!property_exists($consumer, 'args') || !is_array($consumer->args) || count($consumer->args) < 2) {
            return false;
        }
        $argCount = \count($consumer->args);
        $literalPreludeCount = 0;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                // false/true/null (and other consts) inside ['k'=>false] feed the Array_, not a call arg (#26367).
                if ($this->hoistedExprFeedsLaterArrayBeforeConsumer($mid, $j, $consumerIndex, $cfgChildren)) {
                    continue;
                }
                ++$literalPreludeCount;
                continue;
            }
            if ($mid instanceof Op\Expr\Array_) {
                // Nested [] inside ['k'=>[]] is an element prelude, not a separate call arg (#26367).
                if ($this->hoistedExprFeedsLaterArrayBeforeConsumer($mid, $j, $consumerIndex, $cfgChildren)) {
                    continue;
                }
                ++$literalPreludeCount;
                continue;
            }
            if (
                $mid instanceof Op\Expr\FuncCall
                || $mid instanceof Op\Expr\NsFuncCall
                || $mid instanceof Op\Expr\StaticCall
                || $mid instanceof Op\Expr\MethodCall
            ) {
                return false;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }

            return false;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
            while (
                $targetArgIndex < $argCount
                && ($consumer->args[$targetArgIndex] ?? null) instanceof Operand\Literal
            ) {
                ++$targetArgIndex;
            }
        }
        $targetArg = $consumer->args[$targetArgIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($targetArg)) {
            return false;
        }
        // array_splice($a, …); json_encode($a, JSON_*) — stmt FuncCall must not feed named arg (#13573).
        if ($this->isNamedVariableOperand($targetArg)) {
            return false;
        }
        // Trailing hoisted literal preludes only (e.g. var_export(g(), true), in_array('x', g(), true);
        // statement-level calls before multiple hoisted ConstFetch args must not match (#11312, #11373).
        // json_decode(str_repeat(...), true, 512, JSON_THROW_ON_ERROR): embedded 512 is not a cfg prelude (#12009).
        $embeddedLiteralCount = 0;
        $hoistedArgCount = 0;
        foreach ($consumer->args as $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                ++$embeddedLiteralCount;
            } elseif (null !== $callArg) {
                ++$hoistedArgCount;
            }
        }
        $useHoistedArgLiteralPreludeCount = $embeddedLiteralCount > 0
            && (
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\MethodCall
            );
        // Leading embedded literal + middle hoisted producer (in_array('x', g(), true)): trailing
        // ConstFetch preludes count from $targetArgIndex (#11373, #13507). json_decode(g(), true, …)
        // keeps hoisted-arg formula when the producer feeds arg 0 (#12009).
        $expectedLiteralPreludes = $useHoistedArgLiteralPreludeCount
            ? ($targetArgIndex > 0
                ? max(0, $argCount - 1 - $targetArgIndex)
                : max(0, $hoistedArgCount - 1 - $targetArgIndex))
            : ($argCount - 1 - $targetArgIndex);
        if ($literalPreludeCount !== $expectedLiteralPreludes) {
            // var_export(in_array(..., true), true) — nested producer feeds arg0, not arg1 (#11399).
            if (
                0 !== $targetArgIndex
                && $literalPreludeCount > 0
                && null === $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren)
                && $this->callArgIsDeadInlineTemporary($consumer->args[0] ?? null)
                && $literalPreludeCount === $argCount - 1
            ) {
                $targetArgIndex = 0;
                if ($literalPreludeCount !== $argCount - 1 - $targetArgIndex) {
                    return false;
                }
            } else {
                return false;
            }
        }

        if (null === $producer->result) {
            return false;
        }
        if (!$this->operandsReferToSameVariable($producer->result, $targetArg)) {
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling) {
                $firstSibling = $this->nearestHoistedFuncCallProducerBeforeConsumer($consumerIndex, $cfgChildren);
            }
            if (null === $firstSibling || $producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
                return false;
            }
            $leadingEmbedded = 0;
            foreach ($consumer->args as $callArg) {
                if ($this->isEmbeddedCallLiteralArg($callArg)) {
                    ++$leadingEmbedded;
                    continue;
                }
                break;
            }
            $ordinal = $this->siblingFuncCallChainHasArrayPrelude($firstSibling, $consumerIndex, $cfgChildren)
                ? $this->siblingInlineFuncCallProducerOrdinal($producerIndex, $firstSibling, $cfgChildren)
                : ($producerIndex - $firstSibling);
            if ($ordinal < 0 || ($leadingEmbedded + $ordinal) !== $targetArgIndex) {
                return false;
            }
        }

        $scalarPreludeCount = 0;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch) {
                // Array-element true/false/null must not look like trailing callee ConstFetch args (#26367).
                if ($this->hoistedExprFeedsLaterArrayBeforeConsumer($mid, $j, $consumerIndex, $cfgChildren)) {
                    continue;
                }
                $name = $this->staticNameFromOperand($mid->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    ++$scalarPreludeCount;
                }
            }
        }
        if ($scalarPreludeCount > 0) {
            $hoistedArgCount = 0;
            foreach ($consumer->args as $callArg) {
                if (
                    null !== $callArg
                    && !$this->isEmbeddedCallLiteralArg($callArg)
                    && $this->callArgIsDeadInlineTemporary($callArg)
                ) {
                    ++$hoistedArgCount;
                }
            }
            if ($scalarPreludeCount === $hoistedArgCount) {
                // var_dump(...); ini_get_all(null, false) — ConstFetch are callee args, not wiring (#15931, #16065).
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg hoists ConstFetch/Array_ element values before the consuming Array_ call arg (#26367).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedExprFeedsLaterArrayBeforeConsumer(
        Op\Expr $expr,
        int $exprIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $expr->result) {
            return false;
        }
        for ($k = $exprIndex + 1; $k < $consumerIndex; ++$k) {
            $later = $cfgChildren[$k] ?? null;
            if (!$later instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->cfgExprUsesOperand($later, $expr->result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sole FuncCall before Array_ (± element ConstFetch/nested Array_) feeds arg #0 — not distance-1 (#26367).
     *
     * @param list<Op> $cfgChildren
     */
    private function soleFuncCallBeforeArrayLiteralCallArgTargetIndex(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren,
        Op $consumer
    ): ?int {
        if ($producerIndex >= $consumerIndex - 1) {
            return null;
        }
        $hasArrayPrelude = false;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\Array_) {
                $hasArrayPrelude = true;
                continue;
            }
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                continue;
            }

            return null;
        }
        if (!$hasArrayPrelude) {
            return null;
        }
        if (
            !($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || \count($consumer->args) < 2
        ) {
            return null;
        }
        $leadingEmbedded = 0;
        foreach ($consumer->args as $arg) {
            if ($this->isEmbeddedCallLiteralArg($arg)) {
                ++$leadingEmbedded;
                continue;
            }
            break;
        }

        return $leadingEmbedded;
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall stmts before the consumer (#9463, #10981).
     * Skip eager compileOps lowering so each producer gets its own EXEC_RETURN slot at the consumer.
     *
     * @param Op[] $ops
     */
    private function isDeferredSiblingInlineCallArgProducer(Op $op, array $ops, int $producerIndex): bool
    {
        // Soft-null E_DEPRECATED on null literals must run at the consumer call site — hoisted
        // producers that compile early skip intervening set_error_handler() (#21223).
        if (
            ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall)
            && null !== $this->deferredSiblingInlineCallArgConsumerIndex($op, $ops, $producerIndex)
            && $this->funcCallSoftNullDeprecationOnNullMustDeferAtConsumer($op)
        ) {
            return true;
        }
        // chmod(); substr(sprintf('%o', fileperms($path)), -N) — side effects must run in stmt order (#16480).
        if (
            ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall)
            && $this->isStatementLevelSideEffectFuncCall($op)
        ) {
            return false;
        }
        // next()/send()/… may still be value-producing call-arg siblings (#25672); deferral is
        // decided by isSiblingMultiArgFuncCallProducer's dead-temp distance window (#13901).
        // By-ref builtins (sort/natcasesort/array_push/…) mutate args — never defer as inline producers (#12732).
        if ($this->funcCallExprHasByRefMutatingSideEffects($op)) {
            return false;
        }
        // replaceChild(createElement(...), getElementsByTagName(...)->item(0)) — createElement is a
        // dead-temp MethodCall before a multi-arg MethodCall; defer so EXEC_RETURN is forced (#25563).
        if (
            $op instanceof Op\Expr\MethodCall
            && $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps($op, $ops, $producerIndex)
        ) {
            return true;
        }
        // var_dump($s->contains($o), $s[$o], count($s)) — ArrayDimFetch between MethodCall and the
        // multi-arg consumer breaks firstSibling during reentrant stmt walk (#28821). Detect
        // structurally without firstSibling so contains() is deferred and EXEC_RETURN is forced.
        if (
            $op instanceof Op\Expr\MethodCall
            && null !== $this->multiArgConsumerAfterMethodCallDimFetchSibling($ops, $producerIndex)
        ) {
            return true;
        }
        $consumerIndex = $this->deferredSiblingInlineCallArgConsumerIndex($op, $ops, $producerIndex);
        if (null !== $consumerIndex) {
            $consumer = $ops[$consumerIndex] ?? null;
            // Bare stmt MethodCall before trailing true/false/null ConstFetch multi-arg consumer
            // (appendChild then insertBefore($x, null)) must not be deferred (#26458).
            if (
                $op instanceof Op\Expr\MethodCall
                && null !== $consumer
                && (null === $op->result || empty($op->result->usages))
                && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $consumerIndex, $ops)
                && !$this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($op, $consumer)
            ) {
                return false;
            }
            if (
                $op instanceof Op\Expr
                && null !== $consumer
                && $this->isIifeHoistedFuncCallArgProducer(
                    $op,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $ops
                )
            ) {
                return true;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $ops);
            if (null === $firstSibling) {
                return false;
            }

            $consumer = $ops[$consumerIndex] ?? null;

            if ($this->countSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $ops) >= 2
                || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $op,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $ops
                )
                || (
                    $op instanceof Op\Expr
                    && null !== $consumer
                    && $this->isSiblingMultiArgFuncCallProducer(
                        $op,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $ops
                    )
                )
                || (
                    $op instanceof Op\Expr
                    && null !== $consumer
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $op,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $ops
                    )
                )
            ) {
                return true;
            }
        }

        // str_split(str_repeat()) — defer inner g() when outer f() defers for multi-arg consumer (#16031).
        $nextIndex = $producerIndex + 1;
        $next = $ops[$nextIndex] ?? null;
        if (
            ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall)
            && ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
            && $this->isAdjacentNestedFuncCallProducer($op, $next, $producerIndex, $nextIndex)
            && $this->isDeferredSiblingInlineCallArgProducer($next, $ops, $nextIndex)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Defer trailing strcmp(...) FCC until sibling array_keys producers compile at the consumer (#15475).
     *
     * Early TYPE_FROM_CALLABLE before deferred EXEC_RETURN slots breaks JIT closure wiring for array_udiff().
     *
     * @param Op[] $ops
     */
    private function isDeferredTrailingComparatorFirstClassCallable(Op $op, array $ops, int $producerIndex): bool
    {
        if (!$op instanceof Op\Expr\FirstClassCallable) {
            return false;
        }
        $opCount = \count($ops);
        for ($j = $producerIndex + 1; $j < $opCount; ++$j) {
            $consumer = $ops[$j] ?? null;
            if ($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall) {
                $funcName = $this->resolveCfgFuncCallName($consumer);
                if (!$this->builtinUsesTrailingComparatorCallback($funcName)) {
                    break;
                }
                $keysBefore = 0;
                for ($k = $j - 1; $k >= 0 && $keysBefore < 2; --$k) {
                    $scan = $ops[$k] ?? null;
                    if (
                        ($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall)
                        && 'array_keys' === strtolower($this->resolveCfgFuncCallName($scan) ?? '')
                    ) {
                        ++$keysBefore;
                        continue;
                    }
                    if ($scan instanceof Op\Expr\Array_
                        || $scan instanceof Op\Expr\ConstFetch
                        || $scan instanceof Op\Expr\ClassConstFetch
                        || $this->isUnaryInlineSiblingCallArgExpr($scan)
                        || $scan instanceof Op\Expr\FirstClassCallable
                    ) {
                        continue;
                    }
                    break;
                }
                if ($keysBefore >= 2) {
                    return true;
                }
                break;
            }
            if ($this->isSiblingInlineCallProducerExpr($consumer)) {
                continue;
            }
            if ($consumer instanceof Op\Expr\Array_
                || $consumer instanceof Op\Expr\ConstFetch
                || $consumer instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($consumer)
            ) {
                continue;
            }
            break;
        }

        return false;
    }

}
