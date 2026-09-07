<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\Web\Superglobals;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * Sibling inline call-arg EXEC_RETURN predicates + create* dead-temp detect (#36387 / #36403).
 *
 * Extracted from {@see StaticMethodAndFuncCallCompile} so gen-0 split-TU can
 * hollow a smaller Concern TU. Covers siblingInlineCallArgProducerNeedsReturnSlot
 * through methodCallDeadTempFeedsLaterMultiArgMethodCall. Mirrors php-src
 * Zend/zend_compile.c ARG_SEND / FUNCCALL_EXEC_RETURN ordering for hoisted
 * sibling producers and DOM create* factories feeding multi-arg MethodCalls —
 * move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as StaticMethodAndFuncCallCompile).
 */
trait SiblingInlineReturnSlotAndMethodDeadTempDetect
{
    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall producers with dead arg temps (#9463, #10981).
     * Each producer must FUNCCALL_EXEC_RETURN into its result slot before the outer call sends args.
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineCallArgProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (
            null === $cfgCallOp
            || null === $block->orig
            || !$this->isSiblingInlineCallProducerExpr($cfgCallOp)
        ) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (!\is_int($producerIndex) || !$cfgCallOp instanceof Op\Expr) {
            return false;
        }
        $n = \count($cfgChildren);
        // Only consumers in the near window after this producer can use it as a hoisted
        // sibling arg — scanning the whole block was O(n²) firstSibling (#36387).
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !is_array($consumer->args) || \count($consumer->args) < 2) {
                if (
                    property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                    && 1 === \count($consumer->args)
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $cfgCallOp,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    )
                ) {
                    return true;
                }
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            // substr(sprintf(...), -N) — lone hoisted FuncCall + UnaryMinus offset (#10673, #13801).
            if ($this->isSiblingMultiArgFuncCallProducer(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            // show(id($t), $t[0]) / chop($Line['text'], ' ') then $Line['text'][0] — lone FuncCall
            // separated only by ArrayDimFetch sibling args. Without EXEC_RETURN both ARG_SENDs
            // collapse onto the dim slot (Parsedown setext/table, #36380; peer #23354).
            if (
                $this->nestedFuncCallProducerSeparatedByDimFetchPreludesOnly(
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
                && $this->deadInlineTemporaryArgCount($consumer) >= 1
            ) {
                return true;
            }
            if (
                null === $firstSibling
                || $this->countSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren) < 2
            ) {
                continue;
            }
            // Multi-sibling chain (MethodCall + FuncCall around ArrayDimFetch) (#28821).
            return true;
        }

        return false;
    }

    /**
     * php-cfg array_intersect(f(g()), f(g())) — outer hoisted producers need EXEC_RETURN (#15488).
     */
    private function outerSiblingInlineFuncCallProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($producerIndex)) {
            return false;
        }
        for ($consumerIndex = $producerIndex + 1, $n = \count($cfgChildren); $consumerIndex < $n; ++$consumerIndex) {
            // Bound: nested outer producers sit near the consumer (#36387).
            if ($consumerIndex > $producerIndex + 32) {
                break;
            }
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || \count($consumer->args) < 2) {
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $firstSibling > $producerIndex) {
                continue;
            }
            $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
            $hoistedArgCount = 0;
            foreach ($consumer->args as $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    ++$hoistedArgCount;
                }
            }
            if (\count($outer) !== $hoistedArgCount) {
                continue;
            }
            foreach ($outer as $outerProducer) {
                if ($outerProducer === $cfgCallOp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * createElement(...) before replaceChild(..., getElementsByTagName(...)->item(0)) — php-cfg marks
     * the MethodCall result as a dead temp (empty usages), so the usual sibling-producer predicates
     * miss it and EXEC_NORETURN drops the new node (#25563).
     *
     * Restricted to {@code create*} factory MethodCalls: a bare empty-usages MethodCall before a
     * multi-arg consumer also matches statement {@code loadXML} ahead of
     * {@code importNode(getElementsByTagName()->item(), true)}, which then drops the load and leaves
     * {@code documentElement} null (#25605, re-#20284).
     *
     * @param list<Op> $ops
     */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
        Op\Expr\MethodCall $producer,
        array $ops,
        int $producerIndex
    ): bool {
        if ($this->methodCallHasStatementLevelSideEffects($producer)) {
            return false;
        }
        if (null !== $producer->result && !empty($producer->result->usages)) {
            return false;
        }
        // Only DOM/document factory creates are inline dead-temp args for multi-arg MethodCalls
        // (#25563). loadXML/loadHTML/etc. are statement-level even when their bool result is unused
        // (#25605).
        if (!$this->methodCallIsDeadTempCreateFactory($producer)) {
            return false;
        }
        $opCount = \count($ops);
        // Bound: create* factory + property/const preludes + multi-arg MethodCall stay near (#36387).
        $scanEnd = min($opCount, $producerIndex + 1 + 32);
        for ($j = $producerIndex + 1; $j < $scanEnd; ++$j) {
            $next = $ops[$j] ?? null;
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                if (!\is_array($next->args ?? null) || \count($next->args) < 2) {
                    continue;
                }
                $deadTempCount = 0;
                foreach ($next->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        ++$deadTempCount;
                    }
                }
                if ($deadTempCount >= 2) {
                    return true;
                }
                continue;
            }
            if (
                $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\NullsafePropertyFetch
                || $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $next instanceof Op\Expr\FuncCall
                || $next instanceof Op\Expr\NsFuncCall
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }
            if ($next instanceof Op && $this->isSiblingInlineCallProducerExpr($next)) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * createElement / createTextNode / … — dead-temp factories that feed multi-arg MethodCalls (#25563).
     * Not loadXML / query methods (#25605).
     */
    private function methodCallIsDeadTempCreateFactory(Op\Expr\MethodCall $call): bool
    {
        $method = $this->staticNameFromOperand($call->name);
        if (null === $method) {
            return false;
        }

        return str_starts_with(strtolower($method), 'create');
    }

    /** Block-scoped wrapper for {@see methodCallDeadTempFeedsLaterMultiArgMethodCallInOps} (#25563). */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCall(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr\MethodCall || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!\is_int($producerIndex)) {
            return false;
        }

        return $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
            $cfgCallOp,
            $cfgChildren,
            $producerIndex
        );
    }

}
