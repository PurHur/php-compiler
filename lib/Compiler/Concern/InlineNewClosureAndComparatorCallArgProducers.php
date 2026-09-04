<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline NEW / Closure / FCC / trailing-comparator call-arg producers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers matchSingleClosureInlineProducer / FCC / usort* comparator wiring and
 * nested/positional `new` producers used from compileCallArgSends /
 * InlineCallArgProducerMatch / SlotForCallArgResolvers.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineNewClosureAndComparatorCallArgProducers
{
    private function matchSingleClosureInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (
            !($producer instanceof Op\Expr\ArrowFunction)
            && !($producer instanceof Op\Expr\Closure)
        ) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $closureSlots = [];
        foreach ($callArgs as $idx => $arg) {
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if ($this->isNamedVariableOperand($arg)) {
                continue;
            }
            $closureSlots[] = $idx;
        }
        if (1 === count($closureSlots) && $closureSlots[0] === $argIndex) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        // array_filter($a, fn(...), ARRAY_FILTER_USE_*) — callback slot from builtin signature (#10232, #9154).
        if ($callbackArgIndex >= 0 && \count($callArgs) >= 3 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_filter/array_any inline array + fn — callback is arg 1, not arg 0 (#12721).
        if ($callbackArgIndex > 0 && 2 === \count($callArgs) && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_map(fn(...), $arr) — callback is arg 0 (#10651).
        if (
            0 === $callbackArgIndex
            && 2 === \count($callArgs)
            && 0 === $argIndex
            && \count($closureSlots) >= 1
            && 0 === $closureSlots[0]
        ) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }

        return null;
    }

    /** Inline strcmp(...) and other FCC comparators — last callback arg only (#13990, zend_closures.c). */
    private function matchSingleFirstClassCallableInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (!$producer instanceof Op\Expr\FirstClassCallable) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        if ($callbackArgIndex >= 0 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }
        if (1 === \count($callArgs) && 0 === $argIndex) {
            return $producer;
        }

        return null;
    }

    /** array_udiff* / usort* — comparator is the trailing call argument (ext/standard/array.c). */
    private function builtinUsesTrailingComparatorCallback(?string $funcName): bool
    {
        if (null === $funcName || '' === $funcName) {
            return false;
        }

        return \in_array(strtolower($funcName), [
            'usort',
            'uasort',
            'uksort',
            'array_udiff',
            'array_uintersect',
            'array_udiff_assoc',
            'array_uintersect_assoc',
            'array_udiff_uassoc',
            'array_uintersect_uassoc',
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_diff_ukey',
            'array_intersect_ukey',
        ], true);
    }

    /**
     * array_udiff(array_keys(...), array_keys(...), strcmp(...)) — FuncCall/FCC hoists (#13990).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchTrailingComparatorInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?string $funcName
    ): ?Op\Expr {
        if (!$this->builtinUsesTrailingComparatorCallback($funcName)) {
            return null;
        }
        $argCount = \count($callArgs);
        if ($argCount < 2) {
            return null;
        }
        $callbackArgIndex = $argCount - 1;
        $callbackProducer = null;
        $funcProducers = [];
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                $callbackProducer = $producer;
            } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                if ($this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($producer))) {
                    continue;
                }
                $funcProducers[] = $producer;
            }
        }
        if (null === $callbackProducer) {
            $callbackArg = $callArgs[$callbackArgIndex] ?? null;
            if (null !== $callbackArg && (
                $this->isEmbeddedCallLiteralArg($callbackArg)
                || !$this->callArgIsDeadInlineTemporary($callbackArg)
            )) {
                $funcArgIndex = 0;
                foreach ($callArgs as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if ($this->callArgIsDeadInlineTemporary($callArg)) {
                        if ($i === $argIndex) {
                            return $funcProducers[$funcArgIndex] ?? null;
                        }
                        ++$funcArgIndex;
                    }
                }

                return null;
            }

            return null;
        }
        if ($argIndex === $callbackArgIndex) {
            return $callbackProducer;
        }
        $funcArgIndex = 0;
        foreach ($callArgs as $i => $callArg) {
            if ($i >= $callbackArgIndex) {
                break;
            }
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                return $funcProducers[$funcArgIndex] ?? null;
            }
            ++$funcArgIndex;
        }

        return null;
    }

    /**
     * Hoisted FuncCall producers may supply a dead temp slot — not an unrelated named local (#9074).
     */
    private function namedCallArgMayUseFuncCallProducerResult(Op\Expr $producer, Operand $callArg): bool
    {
        if (!$this->isNamedVariableOperand($callArg)) {
            return true;
        }
        if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
            return true;
        }
        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
            return $this->funcCallExprByRefArgMatchesOperand($producer, $callArg);
        }

        return false;
    }

    /** True when a hoisted FuncCall temp is an operand of the consumer call (#8561). */
    private function inlineCallArgProducerFeedsConsumer(Op\Expr $producer, Op $consumer): bool
    {
        if (!property_exists($producer, 'result') || !property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $producerRoot = Block::cfgVarRoot($producer->result);
        foreach ($consumer->args as $callArg) {
            if ($callArg === $producer->result) {
                return true;
            }
            if ($this->operandsReferToSameVariable($callArg, $producer->result)) {
                return true;
            }
            if (null !== $producerRoot && Block::cfgVarRoot($callArg) === $producerRoot) {
                return true;
            }
        }

        return false;
    }

    /** (new C())->f(E::A) — inline New_ feeds MethodCall receiver, not a call arg (#16227). */
    private function inlineNewFeedsCallReceiver(Op\Expr\New_ $new, Op $consumer): bool
    {
        if (!$consumer instanceof Op\Expr\MethodCall) {
            return false;
        }
        $receiver = $consumer->var ?? null;
        if (null === $receiver || null === $new->result) {
            return false;
        }

        return $receiver === $new->result
            || $this->operandsReferToSameVariable($receiver, $new->result);
    }

    /** True when a call operand is `new ClassName(...)` (#9904). */
    private function callArgIsNewExpression(?Operand $callArg): bool
    {
        if (null === $callArg) {
            return false;
        }

        return $this->unwrapOperandChain($callArg) instanceof Op\Expr\New_;
    }

    /** True when php-cfg hoisted an inline `new` producer for this call arg (#9904). */
    private function callArgInlineProducerIsNew(?Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if ($this->callArgIsNewExpression($callArg)) {
            return true;
        }
        // new Outer(new Inner(...), fn() => …) — Closure/arrow arg is never an inline New_ (#19771).
        if ($callArg instanceof Operand && $this->callArgOpsContainInlineClosure($callArg)) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $argCount = \count($cfgCallOp->args);
        if (null !== $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, $cfgCallOp->args)) {
            return true;
        }
        if (\count($producers) === $argCount && isset($producers[$argIndex])) {
            $positional = $producers[$argIndex];
            if ($positional instanceof Op\Expr\New_) {
                // Array_ ctor prelude + New_ aligned 1:1 with (iterator, preserve_keys) is wrong —
                // New_ feeds arg #0 only; trailing bool is a separate ConstFetch (#22702).
                if (
                    0 === $argIndex
                    || !(
                        ($producers[0] ?? null) instanceof Op\Expr\Array_
                        && ($producers[1] ?? null) instanceof Op\Expr\New_
                    )
                ) {
                    return true;
                }
            }
            // attachIterator(new ArrayIterator([...]), …) — Array_ is inner-ctor prelude, New_ feeds arg #0 (#13342).
            if (
                !(
                    0 === $argIndex
                    && $positional instanceof Op\Expr\Array_
                    && ($producers[$argIndex + 1] ?? null) instanceof Op\Expr\New_
                )
            ) {
                return false;
            }
        }

        $matched = $this->matchInlineCallArgProducer($producers, $cfgCallOp->args, $argIndex, $cfgCallOp, $block);

        return $matched instanceof Op\Expr\New_;
    }

    /**
     * Hoisted inline `new` feeding a sibling `new` ctor arg must survive stmt dead-temp release (#14483).
     */
    private function markInlineNewProducerKeepSlotForSiblingConsumer(
        Op\Expr\New_ $producer,
        Block $block,
        int $resultSlot
    ): void {
        if (null === $block->orig) {
            return;
        }
        $children = $block->orig->children;
        $producerIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $producer) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return;
        }
        for ($i = $producerIndex + 1, $n = \count($children); $i < $n; ++$i) {
            $consumer = $children[$i];
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                break;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
                continue;
            }
            foreach (\array_keys($consumer->args) as $argIndex) {
                if (!$this->callArgInlineProducerIsNew($consumer, (int) $argIndex, $block)) {
                    continue;
                }
                $matched = $this->matchInlineCallArgProducer(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($children, $consumer),
                    $consumer->args,
                    (int) $argIndex,
                    $consumer,
                    $block
                );
                if ($matched === $producer) {
                    $block->markDeferredArrayLiteralKeepSlot($resultSlot);

                    return;
                }
            }
            if ($consumer instanceof Op\Expr\New_) {
                break;
            }
        }
    }

    /**
     * new LimitIterator(new ArrayIterator([...]), …) — Array_ prelude + inline New_ feeds outer arg #0 (#12916).
     *
     * @param list<Op\Expr> $producers
     */
    private function isNestedNewCtorArrayPreludeProducerPattern(
        array $producers,
        int $argIndex,
        int $argCount,
        int $producerCount
    ): bool {
        return null !== $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, []);
    }

    /**
     * Inline `new Outer(new Inner([...]), …)` — Array_ prelude (optional) + first New_ (#12916).
     * ClassConstFetch/ConstFetch feeding the *inner* ctor must not bind outer args (#19439).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchNestedNewCtorInlineNewProducer(
        array $producers,
        int $argIndex,
        int $argCount,
        array $callArgs = []
    ): ?Op\Expr\New_ {
        if ($argCount < 1 || \count($producers) < 1 || $argIndex >= \count($producers)) {
            return null;
        }
        if ([] !== $callArgs) {
            $callArg = $callArgs[$argIndex] ?? null;
            // Only wire a nested New_ when this call arg is that New_ (or its dead temp result).
            // Bare dead temps (e.g. outer mode ClassConstFetch) must not steal the inner New_ (#19439).
            $isNewArg = $this->callArgIsNewExpression($callArg);
            $deadTempFedByNew = false;
            if (
                !$isNewArg
                && $callArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                foreach ($producers as $producer) {
                    if (!$producer instanceof Op\Expr\New_) {
                        continue;
                    }
                    if (
                        null !== $producer->result
                        && $this->operandsReferToSameVariable($producer->result, $callArg)
                    ) {
                        $deadTempFedByNew = true;
                        break;
                    }
                    // php-cfg rewrites New_->result into a distinct Temporary on the outer arg (#19439).
                    if (
                        isset($callArg->ops)
                        && \is_array($callArg->ops)
                        && \in_array($producer, $callArg->ops, true)
                    ) {
                        $deadTempFedByNew = true;
                        break;
                    }
                }
            }
            if (!$isNewArg && !$deadTempFedByNew) {
                return null;
            }
            // ClassConstFetch/ConstFetch at $argIndex may be an *inner* ctor prelude
            // (new Outer(new Inner(..., Class::C), …)); skip via the offset walk (#19439).
        }
        $offset = $argIndex;
        $callArg = [] !== $callArgs ? ($callArgs[$argIndex] ?? null) : null;
        while ($offset < \count($producers)) {
            $candidate = $producers[$offset];
            if ($candidate instanceof Op\Expr\New_) {
                // Triple-nested `new Outer(new Mid(new Inner([...])), …)` — producers list the
                // innermost New_ first; only bind the New_ that feeds this call arg (#19770).
                if (null === $callArg || $this->inlineNewProducerFeedsCallArg($candidate, $callArg)) {
                    return $candidate;
                }
                ++$offset;
                continue;
            }
            if (
                $candidate instanceof Op\Expr\Array_
                || $candidate instanceof Op\Expr\ConstFetch
                || $candidate instanceof Op\Expr\ClassConstFetch
            ) {
                ++$offset;
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * True when a dead call-arg temp (or New_ expr) is produced by this inline New_ (#18456, #19771).
     * Prevents Array_/New_/ArrowFunction producer lists from wiring the inner New_ to a Closure arg.
     */
    private function inlineNewProducerFeedsCallArg(Op\Expr\New_ $producer, ?Operand $callArg): bool
    {
        if (null === $callArg) {
            return false;
        }
        if ($this->callArgIsNewExpression($callArg)) {
            $root = $this->unwrapOperandChain($callArg);

            return $root === $producer
                || (
                    $root instanceof Op\Expr\New_
                    && null !== $producer->result
                    && null !== $root->result
                    && $this->operandsReferToSameVariable($producer->result, $root->result)
                );
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return isset($callArg->ops)
            && \is_array($callArg->ops)
            && \in_array($producer, $callArg->ops, true);
    }

    /** Dead call-arg temp whose php-cfg ops include an inline Closure/ArrowFunction (#19771). */
    private function callArgOpsContainInlineClosure(?Operand $callArg): bool
    {
        if (!$callArg instanceof Operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ArrowFunction || $root instanceof Op\Expr\Closure) {
            return true;
        }
        foreach ($callArg->ops ?? [] as $embedded) {
            if ($embedded instanceof Op\Expr\ArrowFunction || $embedded instanceof Op\Expr\Closure) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_key_exists($k, new ArrayObject([...])) — positional New_ with Array_ ctor prelude (#18456).
     * Must not bind producers[argIndex] New_ when that arg is a Closure/ArrowFunction (#19771).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand|null> $callArgs
     */
    private function matchPositionalInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            null === $callArg
            || (
                !$this->callArgIsDeadInlineTemporary($callArg)
                && !$this->callArgIsNewExpression($callArg)
            )
        ) {
            return null;
        }
        $positional = $producers[$argIndex] ?? null;

        if ($positional instanceof Op\Expr\New_) {
            // producers[argIndex] may be an earlier nested New_ while this arg is a trailing
            // ClassConstFetch/flag or Closure dead temp — only bind when the call arg is that New_
            // (#19769 CachingIterator::FULL_CACHE, #19771 CallbackFilterIterator callback).
            return $this->inlineNewProducerFeedsCallArg($positional, $callArg) ? $positional : null;
        }
        if (
            $positional instanceof Op\Expr\Array_
            && null !== $callArg
            && (
                $this->callArgIsNewExpression($callArg)
                || ($callArg instanceof Operand && $this->callArgIsDeadInlineTemporary($callArg))
            )
        ) {
            for ($i = $argIndex + 1, $n = \count($producers); $i < $n; ++$i) {
                $follow = $producers[$i];
                if ($follow instanceof Op\Expr\New_) {
                    return $this->inlineNewProducerFeedsCallArg($follow, $callArg) ? $follow : null;
                }
                if (
                    $follow instanceof Op\Expr\Array_
                    || $follow instanceof Op\Expr\ConstFetch
                    || $follow instanceof Op\Expr\ClassConstFetch
                ) {
                    continue;
                }

                break;
            }
        }

        // take2('x', new FilesystemIterator($dir, SKIP_DOTS)) — sole producer is New_ at
        // producers[0] while the call arg is index 1 (literal first arg has no producer) (#21957).
        if (1 === \count($producers)) {
            $sole = $producers[0];
            if (
                $sole instanceof Op\Expr\New_
                && $this->inlineNewProducerFeedsCallArg($sole, $callArg)
            ) {
                return $sole;
            }
        }

        return null;
    }

    /**
     * iterator_to_array(new LimitIterator(new ArrayIterator([...]), …)) — trailing inline New_ (#12916).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchTrailingInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        if (0 !== $argIndex || 1 !== \count($callArgs)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $last = $producers[\count($producers) - 1] ?? null;

        return $last instanceof Op\Expr\New_ ? $last : null;
    }

    /** Slot for hoisted inline `new` when php-cfg dead temps omit result→slot mapping (#11321). */
    private function slotForInlineNewProducer(Block $block, Op\Expr\New_ $new, array $pendingOps = []): ?string
    {
        $slot = $block->slotForOperand($new->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        $newOrdinal = 0;
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if ($child === $new) {
                    break;
                }
                if ($child instanceof Op\Expr\New_) {
                    ++$newOrdinal;
                }
            }
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW !== $op->type) {
                continue;
            }
            if ($seen === $newOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }
        // compileCallArgSends() may emit New_ into $pendingOps before flushing to $block (#13342).
        foreach (array_reverse($pendingOps) as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_NEW === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /** True when $producer supplies the specific $callArg operand (#9456, #9904). */
    private function inlineCallArgProducerFeedsCallArgOp(Op\Expr $producer, Op $consumer, Operand $callArg): bool
    {
        if (!property_exists($producer, 'result') || !property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $producerRoot = Block::cfgVarRoot($producer->result);
        if ($callArg === $producer->result) {
            return true;
        }
        if ($this->operandsReferToSameVariable($callArg, $producer->result)) {
            return true;
        }
        if (null !== $producerRoot && Block::cfgVarRoot($callArg) === $producerRoot) {
            return true;
        }

        return false;
    }

    /**
     * @param ?Operand $argRoot from Block::cfgVarRoot($arg)
     */
    private function inlineExprCallArgUsesOperand(Op $consumer, Operand $arg, ?Operand $argRoot): bool
    {
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        foreach ($consumer->args as $callArg) {
            if ($callArg === $arg) {
                return true;
            }
            if (null !== $argRoot && Block::cfgVarRoot($callArg) === $argRoot) {
                return true;
            }
        }

        return false;
    }
}
