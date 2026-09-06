<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Substr nested-haystack + method/static call-init exec-return slots (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineCallArgProducerSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see substrNestedHaystackFuncCallAtUnaryMinusPattern}
 * through {@see slotForMethodOrStaticCallInitFollowingExecReturnMatchingArgs}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait SubstrNestedHaystackAndMethodOrStaticCallInitSlots
{
    /**
     * substr(f(...), -N) — nested FuncCall haystack cfg index + callee name (#10673, #16451, #17572).
     *
     * @param list<Op> $cfgChildren
     *
     * @return array{0: int, 1: string}|null
     */
    private function substrNestedHaystackFuncCallAtUnaryMinusPattern(
        Op $cfgCallOp,
        int $callIndex,
        array $cfgChildren
    ): ?array {
        if (
            'substr' !== strtolower($this->resolveInlineCallArgFuncName($cfgCallOp) ?? '')
            || !\is_array($cfgCallOp->args ?? null)
            || \count($cfgCallOp->args) < 2
            || $callIndex < 2
            || $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0] ?? null)
        ) {
            return null;
        }
        if (!$this->isUnaryInlineSiblingCallArgExpr($cfgChildren[$callIndex - 1] ?? null)) {
            return null;
        }
        $probeIndex = $callIndex - 2;
        while ($probeIndex >= 0) {
            $skip = $cfgChildren[$probeIndex] ?? null;
            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            break;
        }
        $producerOp = $cfgChildren[$probeIndex] ?? null;
        if (!($producerOp instanceof Op\Expr\FuncCall || $producerOp instanceof Op\Expr\NsFuncCall)) {
            return null;
        }
        $calleeName = $this->resolveCfgFuncCallName($producerOp);
        if (null === $calleeName || '' === $calleeName) {
            return null;
        }
        if (
            !$this->isNestedCallArgProducerForConsumer(
                $producerOp,
                $cfgCallOp,
                $probeIndex,
                $callIndex,
                $cfgChildren
            )
            || 0 !== $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                $probeIndex,
                $callIndex,
                $cfgChildren
            )
        ) {
            return null;
        }

        return [$probeIndex, $calleeName];
    }

    /**
     * substr(sprintf('%o', fileperms($path)), -N) — UnaryMinus offset + nested FuncCall haystack (#16451, #16480).
     *
     * @param list<Op> $cfgChildren
     */
    private function isSubstrNestedSprintfUnaryMinusPattern(
        Op $cfgCallOp,
        int $callIndex,
        array $cfgChildren
    ): bool {
        return null !== $this->substrNestedHaystackFuncCallAtUnaryMinusPattern(
            $cfgCallOp,
            $callIndex,
            $cfgChildren
        );
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function slotForSubstrNestedHaystackFuncCallExecReturn(
        Block $block,
        int $probeIndex,
        string $calleeName,
        array $cfgChildren
    ): ?string {
        return $this->slotForLastEmittedFuncCallExecReturnByName($block, $calleeName)
            ?? $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                $block,
                $probeIndex,
                $cfgChildren
            )
            ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
    }

    /**
     * FUNCCALL_EXEC_RETURN slots emitted before a hoisted sibling FuncCall chain (e.g. `new` ctor).
     *
     * @param list<Op> $cfgChildren
     */
    private function execReturnOrdinalBaseBeforeSiblingInlineFuncCallChain(
        int $firstSibling,
        array $cfgChildren,
        ?int $consumerIndex = null
    ): int {
        $base = 0;
        for ($j = 0; $j < $firstSibling; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($child instanceof Op\Expr\New_) {
                ++$base;
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                ++$base;
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                || $child instanceof Op\Expr\NullsafeMethodCall
                || $child instanceof Op\Expr\StaticCall
            ) {
                if ($child instanceof Op\Expr\MethodCall && $this->methodCallHasStatementLevelSideEffects($child)) {
                    continue;
                }
                // Prior loadXML()-style MethodCalls compile as EXEC_NORETURN — do not inflate
                // the EXEC_RETURN ordinal base used for sibling MethodCall arg producers (#21182).
                if (
                    $child instanceof Op\Expr\MethodCall
                    && !$this->methodCallInlineProducerSuppliesCallArgValue($child)
                ) {
                    continue;
                }
                $method = $this->staticNameFromOperand($child->name);
                if (null === $method || !$this->methodCallIsKnownVoidReturn($method)) {
                    ++$base;
                }
            }
        }

        return $base;
    }

    /**
     * FUNCCALL_EXEC_RETURN slot for a hoisted inline FuncCall by cfg child index (#15488, #15475).
     *
     * @param list<Op> $cfgChildren
     */
    private function slotForInlineFuncCallProducerExecReturnByCfgIndex(
        Block $block,
        int $producerIndex,
        array $cfgChildren
    ): ?int {
        if ($producerIndex < 0 || $producerIndex >= \count($cfgChildren)) {
            return null;
        }
        $producer = $cfgChildren[$producerIndex] ?? null;
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
        ) {
            return null;
        }
        for ($consumerIndex = $producerIndex + 1, $n = \count($cfgChildren); $consumerIndex < $n; ++$consumerIndex) {
            // Bound: hoisted sibling consumers sit near the producer (#36387).
            if ($consumerIndex > $producerIndex + 32) {
                break;
            }
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!\is_array($consumer->args ?? null) || \count($consumer->args) < 2) {
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (
                null === $firstSibling
                || $producerIndex < $firstSibling
                || $producerIndex >= $consumerIndex
            ) {
                continue;
            }
            if (!$this->isSiblingMultiArgFuncCallProducer(
                $producer,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                continue;
            }
            $siblingOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
                $producerIndex,
                $firstSibling,
                $cfgChildren,
                $consumerIndex
            );
            $legacyBase = $this->execReturnOrdinalBaseBeforeSiblingInlineFuncCallChain(
                $firstSibling,
                $cfgChildren,
                $consumerIndex
            );
            $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
                $firstSibling,
                $consumerIndex,
                $cfgChildren
            );
            $execReturnCount = $block->funccallExecReturnCount();
            if ($this->forceDeferredSiblingCallReturnSlot) {
                // Deferred chain compile emits the next EXEC_RETURN in order (#16254).
                $execOrdinal = $execReturnCount;
            } else {
                $execOrdinal = $execReturnCount - $chainProducerCount + $siblingOrdinal;
            }

            return $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal($block, $execOrdinal);
        }
        $funcCallOrdinal = 0;
        for ($j = 0; $j <= $producerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            if ($this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            if ($j === $producerIndex) {
                // New_ and prior MethodCall/StaticCall also emit FUNCCALL_EXEC_RETURN; omitting
                // them maps array_keys(get_object_vars($o)) onto an earlier implode/string slot
                // after $o->m() in the same block (#26770, related #21981/#25812).
                $nonFuncCallExecBase = 0;
                for ($k = 0; $k < $producerIndex; ++$k) {
                    $prior = $cfgChildren[$k] ?? null;
                    if ($prior instanceof Op\Expr\New_) {
                        ++$nonFuncCallExecBase;
                        continue;
                    }
                    if (
                        $prior instanceof Op\Expr\MethodCall
                        || $prior instanceof Op\Expr\NullsafeMethodCall
                        || $prior instanceof Op\Expr\StaticCall
                    ) {
                        if (
                            $prior instanceof Op\Expr\MethodCall
                            && $this->methodCallHasStatementLevelSideEffects($prior)
                        ) {
                            continue;
                        }
                        if (
                            $prior instanceof Op\Expr\MethodCall
                            && !$this->methodCallInlineProducerSuppliesCallArgValue($prior)
                        ) {
                            continue;
                        }
                        $method = $this->staticNameFromOperand($prior->name);
                        if (null === $method || !$this->methodCallIsKnownVoidReturn($method)) {
                            ++$nonFuncCallExecBase;
                        }
                    }
                }

                return $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                    $block,
                    $funcCallOrdinal + $nonFuncCallExecBase
                );
            }
            ++$funcCallOrdinal;
        }

        return null;
    }

    /**
     * Result slot for a hoisted sibling FuncCall producer — prefer FUNCCALL_EXEC_RETURN (#16029).
     *
     * @param list<Op> $cfgChildren
     */
    private function slotForSiblingInlineCallProducerExecReturnByExpr(
        Block $block,
        Op\Expr $producer,
        Op $consumer,
        array $cfgChildren
    ): ?int {
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
        $consumerIndex = array_search($consumer, $cfgChildren, true);
        if (!is_int($producerIndex) || !is_int($consumerIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling || $producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }
        if (!$this->isSiblingInlineCallProducerExpr($producer)) {
            return null;
        }
        if (
            $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\NsFuncCall
        ) {
            $byCfgIndex = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                $block,
                $producerIndex,
                $cfgChildren
            );
            if (null !== $byCfgIndex) {
                return $byCfgIndex;
            }
        }
        $producerOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren,
            $consumerIndex
        );
        $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
            $firstSibling,
            $consumerIndex,
            $cfgChildren
        );
        $execReturnCount = $block->funccallExecReturnCount();
        if ($this->forceDeferredSiblingCallReturnSlot) {
            $execOrdinal = $execReturnCount;
        } elseif (
            $producer instanceof Op\Expr\MethodCall
            || $producer instanceof Op\Expr\StaticCall
        ) {
            $legacyBase = $this->execReturnOrdinalBaseBeforeSiblingInlineFuncCallChain(
                $firstSibling,
                $cfgChildren,
                $consumerIndex
            );
            $execOrdinal = $legacyBase + $producerOrdinal;
        } else {
            $execOrdinal = $execReturnCount - $chainProducerCount + $producerOrdinal;
        }

        return $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal($block, $execOrdinal);
    }

    /**
     * Inline call-arg producer result — EXEC_RETURN when hoisted siblings drifted operand slots (#16029).
     *
     * @param list<Op> $cfgChildren
     */
    /**
     * is_array(file(..., FILE_* | FILE_*)) — dead arg temp may alias bitmask OR, not file() result (#10474).
     */
    private function slotForNestedFuncCallArrayConsumerProducer(
        Block $block,
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $argIndex
    ): ?string {
        if (
            !($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
        ) {
            return null;
        }
        $callArg = $consumer->args[$argIndex] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
            $block,
            $producer,
            $consumer,
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
        if (
            $this->isAdjacentNestedFuncCallProducer(
                $producer,
                $consumer,
                $producerIndex,
                $this->cfgCallOpIndexInChildren($block->orig->children, $consumer, $block->orig) ?: -1
            )
        ) {
            $recent = $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
            if (null !== $recent) {
                return (string) $recent;
            }
        }

        return $this->slotForInlineCallArgProducerResult(
            $block,
            $producer,
            $consumer,
            $block->orig->children
        );
    }

    private function slotForInlineCallArgProducerResult(
        Block $block,
        Op\Expr $producer,
        ?Op $consumer = null,
        ?array $cfgChildren = null
    ): ?string {
        if (
            null !== $consumer
            && null !== $cfgChildren
            && ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall)
        ) {
            $methodSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                $block,
                $producer,
                $consumer,
                $cfgChildren
            );
            if (null !== $methodSlot) {
                return $methodSlot;
            }
            // Name-paired INIT missing and producer is a dead-temp MethodCall: do not fall through
            // to ordinal EXEC_RETURN (binds prior loadXML) — compileExpr must emit first (#34436).
            if (null === $producer->result || empty($producer->result->usages)) {
                $operandSlot = $block->slotForOperand($producer->result);

                return null !== $operandSlot ? (string) $operandSlot : null;
            }
        }
        if (null !== $consumer && null !== $cfgChildren) {
            $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                $block,
                $producer,
                $consumer,
                $cfgChildren
            );
            if (null !== $execReturn) {
                return (string) $execReturn;
            }
        }
        if ($producer instanceof Op\Expr\NullsafePropertyFetch) {
            $nullsafeSlot = $this->slotForNullsafeResult($block, $producer);
            if (null !== $nullsafeSlot) {
                return (string) $nullsafeSlot;
            }
        }
        $operandSlot = $block->slotForOperand($producer->result);

        return null !== $operandSlot ? (string) $operandSlot : null;
    }

    /**
     * var_export(Color::tryFrom(), true) / var_export($o->m(), true) — match INIT→EXEC_RETURN pair (#18164, #17767).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForMethodOrStaticCallInitFollowingExecReturn(
        Block $block,
        Op\Expr $producer,
        array $pendingOps = []
    ): ?string {
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return null;
        }
        $methodName = $this->staticNameFromOperand($producer->name);
        if (null === $methodName) {
            return null;
        }
        $initType = $producer instanceof Op\Expr\StaticCall
            ? OpCode::TYPE_STATICCALL_INIT
            : OpCode::TYPE_METHODCALL_INIT;
        $needle = strtolower($methodName);
        // Pair each cfg MethodCall producer with its own EXEC_RETURN — dead operand slots
        // reuse across repeated same-named calls (#18183, #18184).
        $producerOrdinal = 0;
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if ($child === $producer) {
                    break;
                }
                if ($child instanceof Op\Expr\MethodCall || $child instanceof Op\Expr\StaticCall) {
                    $priorName = $this->staticNameFromOperand($child->name);
                    if (null !== $priorName && $needle === strtolower($priorName)) {
                        ++$producerOrdinal;
                    }
                }
            }
        }
        $ops = array_merge($block->opCodes, $pendingOps);
        $seenInit = 0;
        foreach ($ops as $i => $op) {
            if ($initType !== $op->type || null === $op->arg2) {
                continue;
            }
            $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
            if ($needle !== strtolower($name ?? '')) {
                continue;
            }
            if ($seenInit !== $producerOrdinal) {
                ++$seenInit;
                continue;
            }
            for ($j = $i + 1, $n = \count($ops); $j < $n; ++$j) {
                $scan = $ops[$j];
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $scan->type && null !== $scan->arg1) {
                    return (string) $scan->arg1;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $scan->type) {
                    break;
                }
            }
            ++$seenInit;
        }

        return null;
    }

    /**
     * Pair METHODCALL_INIT → EXEC_RETURN by callee name and embedded literal args (#21901).
     *
     * When php-cfg emits createElement('y') before createElement('x') (RTL), ordinal pairing
     * by same-named MethodCall alone binds the wrong EXEC_RETURN. Matching the ARG_SEND
     * literal(s) after INIT (Nth occurrence in CFG) disambiguates.
     */
    private function slotForMethodOrStaticCallInitFollowingExecReturnMatchingArgs(
        Block $block,
        Op\Expr $producer
    ): ?string {
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return null;
        }
        $methodName = $this->staticNameFromOperand($producer->name);
        if (null === $methodName) {
            return null;
        }
        $needle = strtolower($methodName);
        $expectedArgLiterals = [];
        if (property_exists($producer, 'args') && \is_array($producer->args)) {
            foreach ($producer->args as $producerArg) {
                if ($producerArg instanceof Operand\Literal) {
                    $expectedArgLiterals[] = (string) $producerArg->value;
                } else {
                    return $this->slotForMethodOrStaticCallInitFollowingExecReturn($block, $producer);
                }
            }
        }
        $occurrence = 0;
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if ($child === $producer) {
                    break;
                }
                if (
                    !($child instanceof Op\Expr\MethodCall || $child instanceof Op\Expr\StaticCall)
                ) {
                    continue;
                }
                $priorName = $this->staticNameFromOperand($child->name);
                if (null === $priorName || $needle !== strtolower($priorName)) {
                    continue;
                }
                if (!property_exists($child, 'args') || !\is_array($child->args)) {
                    continue;
                }
                $priorLiterals = [];
                $allLiteral = true;
                foreach ($child->args as $priorArg) {
                    if ($priorArg instanceof Operand\Literal) {
                        $priorLiterals[] = (string) $priorArg->value;
                    } else {
                        $allLiteral = false;
                        break;
                    }
                }
                if ($allLiteral && $priorLiterals === $expectedArgLiterals) {
                    ++$occurrence;
                }
            }
        }
        $initType = $producer instanceof Op\Expr\StaticCall
            ? OpCode::TYPE_STATICCALL_INIT
            : OpCode::TYPE_METHODCALL_INIT;
        $ops = $block->opCodes;
        $seenMatch = 0;
        foreach ($ops as $i => $op) {
            if ($initType !== $op->type || null === $op->arg2) {
                continue;
            }
            $name = $this->resolveCompileTimeStringSlot((int) $op->arg2, $block);
            if ($needle !== strtolower($name ?? '')) {
                continue;
            }
            $argLiterals = [];
            $execSlot = null;
            for ($j = $i + 1, $n = \count($ops); $j < $n; ++$j) {
                $scan = $ops[$j];
                if (OpCode::TYPE_ARG_SEND === $scan->type && null !== $scan->arg1) {
                    $lit = $this->resolveCompileTimeStringSlot((int) $scan->arg1, $block);
                    if (null !== $lit) {
                        $argLiterals[] = $lit;
                    }
                    continue;
                }
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $scan->type && null !== $scan->arg1) {
                    $execSlot = (string) $scan->arg1;
                    break;
                }
                if (
                    OpCode::TYPE_FUNCCALL_INIT === $scan->type
                    || OpCode::TYPE_METHODCALL_INIT === $scan->type
                    || OpCode::TYPE_STATICCALL_INIT === $scan->type
                ) {
                    break;
                }
            }
            if (null === $execSlot || $argLiterals !== $expectedArgLiterals) {
                continue;
            }
            if ($seenMatch === $occurrence) {
                return $execSlot;
            }
            ++$seenMatch;
        }

        return null;
    }
}
