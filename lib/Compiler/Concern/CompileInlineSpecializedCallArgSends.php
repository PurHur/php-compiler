<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\StdlibConstants;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Specialized inline call-arg ARG_SEND compilers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers explode / iterator_to_array / array_chunk / array_walk / array_pad /
 * unpack / extract / date_sun_* and related trailing-comparator inline send
 * helpers invoked from {@see CompileCallArgSends}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait CompileInlineSpecializedCallArgSends
{
    /**
     * explode(PATH_SEPARATOR, get_include_path()) — hoisted ConstFetch separator + sibling FuncCall haystack (#15833).
     *
     * @return list<OpCode>|null
     */
    private function compileExplodeLeadingConstFetchFuncCallInlineCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        $blockOpsAtEntry = \count($block->opCodes);
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        if ('explode' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (2 !== \count($cfgCallOp->args)) {
            return null;
        }
        if (
            isset($cfgCallOp->args[0])
            && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0])
        ) {
            return null;
        }
        if ($this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)) {
            return null;
        }
        $prelude = $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block);
        if (null === $prelude) {
            return null;
        }
        [$constFetch, $funcProducer] = $prelude;
        if (!$constFetch instanceof Op\Expr\ConstFetch) {
            return null;
        }
        if (
            !($funcProducer instanceof Op\Expr\FuncCall || $funcProducer instanceof Op\Expr\NsFuncCall)
        ) {
            return null;
        }

        $producerOps = [];
        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
        $prevInlineNested = $this->inlineNestedProducerOpsInArgSends;
        $this->forceDeferredSiblingCallReturnSlot = true;
        $this->inlineNestedProducerOpsInArgSends = true;
        try {
            $blockOpsBeforeConst = \count($block->opCodes);
            if (null === $block->slotForOperand($constFetch->result)) {
                foreach ($this->compileExpr($constFetch, $block) as $op) {
                    $producerOps[] = $op;
                }
            }
            $producerOps = array_merge(
                $producerOps,
                $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeConst)
            );
            $separatorSlot = $block->slotForOperand($constFetch->result);
            if (null === $separatorSlot) {
                return null;
            }
            $separatorSlot = (string) $separatorSlot;

            $blockOpsBeforeFunc = \count($block->opCodes);
            $funcOps = $this->compileExpr($funcProducer, $block);
            $producerOps = array_merge(
                $producerOps,
                $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeFunc),
                $funcOps
            );
        } finally {
            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            $this->inlineNestedProducerOpsInArgSends = $prevInlineNested;
        }

        $haystackSlot = null;
        foreach (array_reverse($producerOps) as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $haystackSlot = (string) $op->arg1;
                break;
            }
        }
        if (null === $haystackSlot) {
            return null;
        }

        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => $separatorSlot,
                1 => $haystackSlot,
                default => null,
            };
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                null
            );
        }

        $strayBlockOps = $this->drainBlockOpcodesAppendedSince($block, $blockOpsAtEntry);
        if ([] !== $strayBlockOps) {
            $producerOps = array_merge($strayBlockOps, $producerOps);
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * iterator_to_array(new ArrayIterator|ArrayObject([...]), false) — Array_ ctor prelude + New_
     * + trailing preserve_keys ConstFetch (#22702, re-#11321). General dead-temp matching binds both
     * ARG_SENDs to the New_ slot; wire New_ → arg0 and ConstFetch → arg1 explicitly.
     *
     * @return list<OpCode>|null
     */
    private function compileIteratorToArrayInlineNewPreserveKeysCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        $blockOpsAtEntry = \count($block->opCodes);
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        if ('iterator_to_array' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (2 !== \count($cfgCallOp->args)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $constProducer = $block->orig->children[$callIndex - 1] ?? null;
        if (!$constProducer instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $constName = strtolower($this->staticNameFromOperand($constProducer->name) ?? '');
        if (!\in_array($constName, ['true', 'false'], true)) {
            return null;
        }
        $newProducer = null;
        for ($i = $callIndex - 2; $i >= 0; --$i) {
            $candidate = $block->orig->children[$i] ?? null;
            if ($candidate instanceof Op\Expr\New_) {
                $newProducer = $candidate;
                break;
            }
            if ($candidate instanceof Op\Expr\Array_) {
                continue;
            }
            break;
        }
        if (!$newProducer instanceof Op\Expr\New_) {
            return null;
        }
        $arg0 = $cfgCallOp->args[0] ?? null;
        $arg1 = $cfgCallOp->args[1] ?? null;
        if (
            !$arg0 instanceof Operand
            || !$arg1 instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($arg0)
            || !$this->callArgIsDeadInlineTemporary($arg1)
        ) {
            return null;
        }

        $producerOps = [];
        if (null === $block->slotForOperand($newProducer->result)) {
            $blockOpsBeforeNew = \count($block->opCodes);
            foreach ($this->compileExpr($newProducer, $block) as $op) {
                $producerOps[] = $op;
            }
            $producerOps = array_merge(
                $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeNew),
                $producerOps
            );
        }
        $newSlot = $this->slotForInlineNewProducer($block, $newProducer, $producerOps);
        if (null === $newSlot) {
            return null;
        }

        $blockOpsBeforeConst = \count($block->opCodes);
        $preserveKeysSlot = $this->slotForHoistedScalarConstFetchCallArg($constProducer, $block);
        $producerOps = array_merge(
            $producerOps,
            $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeConst)
        );
        if (null === $preserveKeysSlot) {
            return null;
        }

        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $newSlot,
                1 => (string) $preserveKeysSlot,
                default => null,
            };
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                null
            );
        }

        $strayBlockOps = $this->drainBlockOpcodesAppendedSince($block, $blockOpsAtEntry);
        if ([] !== $strayBlockOps) {
            $producerOps = array_merge($strayBlockOps, $producerOps);
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * array_chunk(range(1,5), 2, true) — hoisted FuncCall haystack + ConstFetch preserve_keys (#11767).
     *
     * @return list<OpCode>|null
     */
    private function compileArrayChunkInlineNestedCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        $blockOpsAtEntry = \count($block->opCodes);
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        if ('array_chunk' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (\count($cfgCallOp->args) < 3) {
            return null;
        }
        if (!$this->isEmbeddedCallLiteralArg($cfgCallOp->args[1] ?? null)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $funcProducer = $block->orig->children[$callIndex - 2] ?? null;
        $constProducer = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($funcProducer instanceof Op\Expr\FuncCall || $funcProducer instanceof Op\Expr\NsFuncCall)
            || !$constProducer instanceof Op\Expr\ConstFetch
        ) {
            return null;
        }
        $constName = strtolower($this->staticNameFromOperand($constProducer->name) ?? '');
        if (!\in_array($constName, ['true', 'false'], true)) {
            return null;
        }
        if (
            !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $funcProducer,
                $cfgCallOp,
                $callIndex - 2,
                $callIndex,
                $block->orig->children
            )
        ) {
            return null;
        }
        $producerOps = [];
        $haystackSlot = null;
        $prevForce = $this->forceDeferredSiblingCallReturnSlot;
        $prevInlineNested = $this->inlineNestedProducerOpsInArgSends;
        $this->forceDeferredSiblingCallReturnSlot = true;
        $this->inlineNestedProducerOpsInArgSends = true;
        try {
            $blockOpsBeforeProducer = \count($block->opCodes);
            $producerOps = $this->compileExpr($funcProducer, $block);
            $producerOps = array_merge(
                $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeProducer),
                $producerOps
            );
        } finally {
            $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            $this->inlineNestedProducerOpsInArgSends = $prevInlineNested;
        }
        foreach ($producerOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $haystackSlot = (string) $op->arg1;
                break;
            }
        }
        if (null === $haystackSlot) {
            return null;
        }
        $blockOpsBeforeConst = \count($block->opCodes);
        $preserveKeysSlot = $this->slotForHoistedScalarConstFetchCallArg($constProducer, $block);
        $producerOps = array_merge(
            $producerOps,
            $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeConst)
        );
        if (null === $preserveKeysSlot) {
            $blockOpsBeforeConstCompile = \count($block->opCodes);
            foreach ($this->compileExpr($constProducer, $block) as $op) {
                $producerOps[] = $op;
            }
            $producerOps = array_merge(
                $producerOps,
                $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeConstCompile)
            );
            $preserveKeysSlot = $this->slotForHoistedScalarConstFetchCallArg($constProducer, $block);
        }
        if (null === $preserveKeysSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => $haystackSlot,
                2 => (string) $preserveKeysSlot,
                default => null,
            };
            $literalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, null, null);
        }

        $strayBlockOps = $this->drainBlockOpcodesAppendedSince($block, $blockOpsAtEntry);
        if ([] !== $strayBlockOps) {
            $producerOps = array_merge($strayBlockOps, $producerOps);
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * array_pad([E::A], N, E::B) — inline enum haystack Array_ + trailing pad-value ConstFetch (#8883).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileArrayWalkInlineNewClosureCallArgSends(
        array $args,
        Block $block,
        ?Op $cfgCallOp
    ): ?array {
        if (null === $cfgCallOp || null === $block->orig || 2 !== \count($args)) {
            return null;
        }
        $funcName = $this->resolveCfgFuncCallName($cfgCallOp);
        if (!\in_array($funcName, ['array_walk', 'array_walk_recursive'], true)) {
            return null;
        }
        if (
            !$this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null)
            || !$this->callArgIsDeadInlineTemporary($cfgCallOp->args[1] ?? null)
        ) {
            return null;
        }
        $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
        if (
            !$leadingCallback instanceof Op\Expr\Closure
            && !$leadingCallback instanceof Op\Expr\ArrowFunction
        ) {
            return null;
        }
        $inlineNew = $this->leadingInlineNewBeforeCallbackBeforeCfgCall($cfgCallOp, $block);
        if (!$inlineNew instanceof Op\Expr\New_) {
            return null;
        }
        $producerOps = [];
        $subjectSlot = $block->slotForOperand($inlineNew->result);
        if (null === $subjectSlot) {
            foreach ($this->compileExpr($inlineNew, $block) as $op) {
                $producerOps[] = $op;
            }
            $subjectSlot = $this->slotForInlineNewProducer($block, $inlineNew, $producerOps);
        }
        if (null === $subjectSlot) {
            return null;
        }
        $callbackSlot = $block->slotForOperand($leadingCallback->result);
        if (null === $callbackSlot) {
            foreach ($this->compileExpr($leadingCallback, $block) as $op) {
                $producerOps[] = $op;
            }
            $callbackSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
        }
        if (null === $callbackSlot) {
            return null;
        }
        $sends = $producerOps;
        foreach ($args as $argIndex => $arg) {
            $nameSlot = $this->callArgNameSlot($arg, $block);
            $valueSlot = 0 === (int) $argIndex ? (string) $subjectSlot : (string) $callbackSlot;
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, null);
        }

        return $sends;
    }

    /**
     * array_filter(explode(...), fn(...)) / array_filter(str_split(...), is_numeric(...)) — hoisted haystack
     * FuncCall + callback (closure/FCC) before the consumer; wire arg 0/1 explicitly (#17948, #15490, #15961).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileInlineClosurePairHaystackCallbackCallArgSends(
        array $args,
        Block $block,
        ?Op $cfgCallOp
    ): ?array {
        if (null === $cfgCallOp || null === $block->orig || 2 !== \count($args)) {
            return null;
        }
        $haystackArg = $args[0] ?? null;
        if (
            $haystackArg instanceof Operand
            && $this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($cfgCallOp, 0, $haystackArg)
        ) {
            return null;
        }
        $funcName = $this->resolveCfgFuncCallName($cfgCallOp);
        if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? $args[0] ?? null;
        if (
            $haystackArg instanceof Operand
            && (
                $this->isNamedVariableOperand($haystackArg)
                || $this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($cfgCallOp, 0, $haystackArg)
            )
        ) {
            // array_walk($a, fn) — real CV haystack, not a hoisted sibling FuncCall (#17989).
            return null;
        }
        $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
        if (
            !$leadingCallback instanceof Op\Expr\Closure
            && !$leadingCallback instanceof Op\Expr\ArrowFunction
            && !$leadingCallback instanceof Op\Expr\FirstClassCallable
        ) {
            return null;
        }
        $haystackProducer = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
        if (
            !$haystackProducer instanceof Op\Expr\FuncCall
            && !$haystackProducer instanceof Op\Expr\NsFuncCall
        ) {
            return null;
        }
        $producerOps = [];
        $cfgChildren = $block->orig->children;
        $haystackIndex = array_search($haystackProducer, $cfgChildren, true);
        // Prefer the producer's bound EXEC_RETURN / compile it. Do not use CFG-index ordinal
        // lookup here — with prior filter+var_dump in the same block it steals the wrong
        // EXEC_RETURN (#27344 / #15490).
        $haystackSlot = $block->slotForOperand($haystackProducer->result);
        if (null === $haystackSlot) {
            foreach ($this->compileExpr($haystackProducer, $block) as $op) {
                $producerOps[] = $op;
            }
            $haystackSlot = $block->slotForOperand($haystackProducer->result)
                ?? (
                    \is_int($haystackIndex)
                        ? $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $haystackIndex,
                            $cfgChildren
                        )
                        : null
                )
                ?? $this->slotForLastInlineFuncCallExecReturn($block, $producerOps);
        }
        if (null === $haystackSlot) {
            return null;
        }
        $callbackSlot = $block->slotForOperand($leadingCallback->result);
        if (null === $callbackSlot) {
            if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                $callbackSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
            } else {
                foreach ($this->compileExpr($leadingCallback, $block) as $op) {
                    $producerOps[] = $op;
                }
                $callbackSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
            }
        }
        if (null === $callbackSlot) {
            return null;
        }
        $sends = $producerOps;
        foreach ($args as $argIndex => $arg) {
            $nameSlot = $this->callArgNameSlot($arg, $block);
            $valueSlot = 0 === (int) $argIndex ? (string) $haystackSlot : (string) $callbackSlot;
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, null);
        }

        return $sends;
    }

    /**
     * usort($a = explode(...), fn(...)) — hoisted Assign + comparator callback (#17950, ext/standard/array.c).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileTrailingComparatorInlineAssignCallbackCallArgSends(
        array $args,
        Block $block,
        ?Op $cfgCallOp
    ): ?array {
        if (null === $cfgCallOp || null === $block->orig || 2 !== \count($args)) {
            return null;
        }
        if (!$this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($cfgCallOp))) {
            return null;
        }
        $assign = $this->trailingAssignBeforeComparatorCallbackBeforeCfgCall($cfgCallOp, $block);
        if (!$assign instanceof Op\Expr\Assign) {
            return null;
        }
        $inlineAssignArg = $cfgCallOp->args[0] ?? null;
        if (
            !$inlineAssignArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($inlineAssignArg)
            || $this->isNamedVariableOperand($inlineAssignArg)
        ) {
            return null;
        }
        $leadingCallback = $this->leadingComparatorCallbackInlineProducerBeforeCfgCall($cfgCallOp, $block);
        if (
            !$leadingCallback instanceof Op\Expr\Closure
            && !$leadingCallback instanceof Op\Expr\ArrowFunction
            && !$leadingCallback instanceof Op\Expr\FirstClassCallable
        ) {
            return null;
        }
        $producerOps = [];
        $assignSlot = $block->slotForOperand($assign->result);
        if (null === $assignSlot) {
            foreach ($this->compileExpr($assign, $block) as $op) {
                $producerOps[] = $op;
            }
            $assignSlot = $block->slotForOperand($assign->result)
                ?? $this->slotForEmittedAssignResultSlot($block, $assign);
        }
        if (null === $assignSlot) {
            return null;
        }
        $callbackSlot = $block->slotForOperand($leadingCallback->result);
        if (null === $callbackSlot) {
            if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                $callbackSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
            } else {
                foreach ($this->compileExpr($leadingCallback, $block) as $op) {
                    $producerOps[] = $op;
                }
                $callbackSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
            }
        }
        if (null === $callbackSlot) {
            return null;
        }
        $sends = $producerOps;
        foreach ($args as $argIndex => $arg) {
            $nameSlot = $this->callArgNameSlot($arg, $block);
            $valueSlot = 0 === (int) $argIndex ? (string) $assignSlot : (string) $callbackSlot;
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, null);
        }

        return $sends;
    }

    /** usort($a = f(), fn) — Assign stmt immediately before trailing comparator callback (#17950). */
    private function trailingAssignBeforeComparatorCallbackBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr\Assign
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        if (!$this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($cfgCallOp))) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $callback = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$callback instanceof Op\Expr\ArrowFunction
            && !$callback instanceof Op\Expr\Closure
            && !$callback instanceof Op\Expr\FirstClassCallable
        ) {
            return null;
        }
        $assign = $block->orig->children[$callIndex - 2] ?? null;

        return $assign instanceof Op\Expr\Assign ? $assign : null;
    }

    /** usort(..., fn) / array_udiff(..., strcmp) — trailing comparator callback hoisted before consumer (#17950). */
    private function leadingComparatorCallbackInlineProducerBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        if (!$this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($cfgCallOp))) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if ($prev instanceof Op\Expr\ArrowFunction
            || $prev instanceof Op\Expr\Closure
            || $prev instanceof Op\Expr\FirstClassCallable) {
            return $prev;
        }

        return null;
    }

    private function compileArrayPadInlineHaystackCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('array_pad' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (\count($cfgCallOp->args) < 3) {
            return null;
        }
        // array_pad([1], Len::Two, 0) — ClassConstFetch is length, not pad value (#16560).
        if (
            $this->callArgIsDeadInlineTemporary($cfgCallOp->args[1] ?? null)
            && !$this->callArgIsDeadInlineTemporary($cfgCallOp->args[2] ?? null)
        ) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($haystackArg)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $arrayProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $arrayProducer = $producer;
                break;
            }
        }
        if (null === $arrayProducer) {
            return null;
        }
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp,
            $block
        );
        /** @var array<int, Op\Expr\ClassConstFetch> $classConstFetchByArgIndex */
        $classConstFetchByArgIndex = [];
        foreach ([1, 2] as $constArgIndex) {
            $fetch = $this->precedingClassConstFetchForCallArgIndex($cfgCallOp, $constArgIndex, $fetches);
            if ($fetch instanceof Op\Expr\ClassConstFetch) {
                $classConstFetchByArgIndex[$constArgIndex] = $fetch;
            }
        }
        // array_pad([E::A], N, E::B) — pad-value ClassConstFetch is arg #2 only (#8883, #16560).
        if ([] === $classConstFetchByArgIndex) {
            return null;
        }
        $producerOps = [];
        foreach ($this->compileArrayLiteral($arrayProducer, $block) as $op) {
            $producerOps[] = $op;
        }
        $haystackSlot = $this->slotForInitArrayOrdinal($block, 0, $producerOps);
        if (null === $haystackSlot) {
            return null;
        }
        $constFetchSlots = [];
        foreach ($classConstFetchByArgIndex as $constArgIndex => $fetchProducer) {
            if (null === $block->slotForOperand($fetchProducer->result)) {
                foreach ($this->compileExpr($fetchProducer, $block) as $op) {
                    $producerOps[] = $op;
                }
            }
            $fetchSlot = $block->slotForOperand($fetchProducer->result);
            if (null === $fetchSlot) {
                return null;
            }
            $constFetchSlots[$constArgIndex] = (string) $fetchSlot;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $haystackSlot,
                1, 2 => $constFetchSlots[(int) $argIndex] ?? null,
                default => null,
            };
            $literalProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * unpack('i', pack('i', 1), E::A) — inline pack string + trailing enum offset (#8866).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileUnpackInlinePackEnumOffsetCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('unpack' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (3 !== \count($cfgCallOp->args)) {
            return null;
        }
        $stringArg = $cfgCallOp->args[1] ?? null;
        $offsetArg = $cfgCallOp->args[2] ?? null;
        if (
            !$this->callArgIsDeadInlineTemporary($stringArg)
            || !$this->callArgUsesHoistedEnumPreludeSlot($offsetArg)
            || !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[0] ?? null)
        ) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $packProducer = null;
        $enumProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $packProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $enumProducer = $producer;
            }
        }
        if (null === $packProducer || null === $enumProducer) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        $packIndex = array_search($packProducer, $block->orig->children, true);
        if (!\is_int($callIndex) || !\is_int($packIndex)) {
            return null;
        }
        $producerOps = [];
        if (null === $block->slotForOperand($enumProducer->result)) {
            foreach ($this->compileExpr($enumProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $enumSlot = $block->slotForOperand($enumProducer->result);
        if (null === $enumSlot) {
            return null;
        }
        if (null === $block->slotForOperand($packProducer->result)) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($packProducer, $block) as $op) {
                    $producerOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
        }
        $packSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
            $block,
            $packIndex,
            $block->orig->children
        ) ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block)
            ?? $block->slotForOperand($packProducer->result);
        if (null === $packSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                1 => (string) $packSlot,
                2 => (string) $enumSlot,
                default => null,
            };
            $literalProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * array_pad([1], 4, 0, ArrayPadType::Positive) — inline Array_ + trailing pad_type ClassConstFetch (#17240).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileArrayPadInlinePadTypeEnumCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('array_pad' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (4 !== \count($cfgCallOp->args)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        $padTypeArg = $cfgCallOp->args[3] ?? null;
        if (
            !$this->callArgIsDeadInlineTemporary($haystackArg)
            || !$this->callArgUsesHoistedEnumPreludeSlot($padTypeArg)
        ) {
            return null;
        }
        foreach ([1, 2] as $literalArgIndex) {
            if ($this->callArgUsesHoistedEnumPreludeSlot($cfgCallOp->args[$literalArgIndex] ?? null)) {
                return null;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $arrayProducer = null;
        $padTypeProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $arrayProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $padTypeProducer = $producer;
            }
        }
        if (null === $arrayProducer || null === $padTypeProducer) {
            return null;
        }
        $producerOps = [];
        $existingHaystackSlot = $block->slotForOperand($arrayProducer->result);
        if (null === $existingHaystackSlot) {
            foreach ($this->compileArrayLiteral($arrayProducer, $block) as $op) {
                $producerOps[] = $op;
            }
            $haystackSlot = $this->slotForInitArrayOrdinal($block, 0, $producerOps);
        } else {
            $haystackSlot = (string) $existingHaystackSlot;
        }
        if (null === $haystackSlot) {
            return null;
        }
        if (null === $block->slotForOperand($padTypeProducer->result)) {
            foreach ($this->compileExpr($padTypeProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $padTypeSlot = $block->slotForOperand($padTypeProducer->result);
        if (null === $padTypeSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $haystackSlot,
                3 => (string) $padTypeSlot,
                default => null,
            };
            $literalProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * array_chunk([1, 2, 3], Len::Two) — inline Array_ haystack + ClassConstFetch length (#9971, #16560).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileArrayChunkInlineArrayClassConstArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('array_chunk' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (2 !== \count($cfgCallOp->args)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        $lengthArg = $cfgCallOp->args[1] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($haystackArg) || !$this->callArgIsDeadInlineTemporary($lengthArg)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $arrayProducer = null;
        $lengthProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $arrayProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $lengthProducer = $producer;
            }
        }
        if (null === $arrayProducer || null === $lengthProducer) {
            return null;
        }
        $producerOps = [];
        foreach ($this->compileArrayLiteral($arrayProducer, $block) as $op) {
            $producerOps[] = $op;
        }
        $haystackSlot = $this->slotForInitArrayOrdinal($block, 0, $producerOps);
        if (null === $haystackSlot) {
            return null;
        }
        if (null === $block->slotForOperand($lengthProducer->result)) {
            foreach ($this->compileExpr($lengthProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $lengthSlot = $block->slotForOperand($lengthProducer->result);
        if (null === $lengthSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $haystackSlot,
                1 => (string) $lengthSlot,
                default => null,
            };
            $literalProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * array_pad([1], Len::Two, 0) — inline Array_ haystack + ClassConstFetch length (#9971, #16560).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileArrayPadInlineArrayClassConstLengthCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('array_pad' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (3 !== \count($cfgCallOp->args)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        $lengthArg = $cfgCallOp->args[1] ?? null;
        if (
            !$this->callArgIsDeadInlineTemporary($haystackArg)
            || !$this->callArgIsDeadInlineTemporary($lengthArg)
            || $this->callArgIsDeadInlineTemporary($cfgCallOp->args[2] ?? null)
        ) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $arrayProducer = null;
        $lengthProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $arrayProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $lengthProducer = $producer;
            }
        }
        if (null === $arrayProducer || null === $lengthProducer) {
            return null;
        }
        $producerOps = [];
        foreach ($this->compileArrayLiteral($arrayProducer, $block) as $op) {
            $producerOps[] = $op;
        }
        $haystackSlot = $this->slotForInitArrayOrdinal($block, 0, $producerOps);
        if (null === $haystackSlot) {
            return null;
        }
        if (null === $block->slotForOperand($lengthProducer->result)) {
            foreach ($this->compileExpr($lengthProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $lengthSlot = $block->slotForOperand($lengthProducer->result);
        if (null === $lengthSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $haystackSlot,
                1 => (string) $lengthSlot,
                default => null,
            };
            $literalProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * extract(['a' => 1], EXTR_PREFIX_ALL, Prefix::A) — inline Array_ + flags ConstFetch + prefix ClassConstFetch (#16041).
     *
     * @param list<Operand|null> $args
     *
     * @return list<OpCode>|null
     */
    private function compileExtractInlineMultiArgCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        if ('extract' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (3 !== \count($cfgCallOp->args)) {
            return null;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return null;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $arrayProducer = null;
        $flagsProducer = null;
        $prefixProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $arrayProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $flagsProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $prefixProducer = $producer;
            }
        }
        if (null === $arrayProducer || null === $flagsProducer || null === $prefixProducer) {
            return null;
        }
        $producerOps = [];
        foreach ($this->compileArrayLiteral($arrayProducer, $block) as $op) {
            $producerOps[] = $op;
        }
        $arraySlot = $this->slotForInitArrayOrdinal($block, 0, $producerOps);
        if (null === $arraySlot) {
            return null;
        }
        if (null === $block->slotForOperand($flagsProducer->result)) {
            foreach ($this->compileExpr($flagsProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $flagsSlot = $block->slotForOperand($flagsProducer->result);
        if (null === $flagsSlot) {
            return null;
        }
        if (null === $block->slotForOperand($prefixProducer->result)) {
            foreach ($this->compileExpr($prefixProducer, $block) as $op) {
                $producerOps[] = $op;
            }
        }
        $prefixSlot = $block->slotForOperand($prefixProducer->result);
        if (null === $prefixSlot) {
            return null;
        }
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = match ((int) $argIndex) {
                0 => (string) $arraySlot,
                1 => (string) $flagsSlot,
                2 => (string) $prefixSlot,
                default => null,
            };
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(
                OpCode::TYPE_ARG_SEND,
                $valueSlot,
                $this->callArgNameSlot($arg, $block),
                $this->callArgUnpack($arg) ? 1 : null
            );
        }

        return array_merge($producerOps, $sends);
    }

    /**
     * date_sunrise(time(), SUNFUNCS_RET_*, …) / date_sun_info(strtotime(...), lat, -lon) — bypass sibling producer scan (#13749, #16012, #11336).
     *
     * @return list<OpCode>|null
     */
    private function compileDateSunFuncInlineCallArgSends(
        array $args,
        Block $block,
        Op $cfgCallOp
    ): ?array {
        if (null === $block->orig) {
            return null;
        }
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        $isDateSunInfo = 'date_sun_info' === $callee;
        if (!\in_array($callee, ['date_sunrise', 'date_sunset', 'date_sun_info'], true)) {
            return null;
        }
        // Named calls must keep ARG_SEND labels so BuiltinParamNames can reject legacy
        // time:/format:/gmt_offset: like Zend (#24363). Hoist path is positional-only.
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            return null;
        }
        $blockOpsAtEntry = \count($block->opCodes);
        $producerOps = [];
        $timeArgSlot = null;
        $sunfuncsArgSlot = null;
        $longitudeSlot = null;
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
            if ($prelude instanceof Op\Expr\ConstFetch) {
                $name = strtolower($this->staticNameFromOperand($prelude->name) ?? '');
                if (!str_starts_with($name, 'sunfuncs_ret_')) {
                    continue;
                }
                $folded = $this->tryFoldGlobalConstFetch($prelude);
                if (null === $folded) {
                    $stdlibInt = \PHPCompiler\ext\standard\StdlibConstants::coreIntByName($name);
                    if (null !== $stdlibInt) {
                        $folded = new Variable(Variable::TYPE_INTEGER);
                        $folded->int($stdlibInt);
                    }
                }
                if (null === $folded) {
                    continue;
                }
                $sunfuncsArgSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
            if ($prelude instanceof Op\Expr\UnaryMinus || $prelude instanceof Op\Expr\UnaryPlus) {
                $foldedUnary = $this->tryFoldUnaryLiteralDefault($prelude);
                if (null !== $foldedUnary) {
                    $longitudeSlot = (string) $block->registerConstant(new Operand\Temporary(), $foldedUnary);
                }
            }
        }
        if (null !== $callIndex) {
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\Assign) {
                    break;
                }
                if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
                    continue;
                }
                $producerName = strtolower($this->resolveCfgFuncCallName($child) ?? '');
                if (!\in_array($producerName, ['time', 'gmmktime', 'strtotime'], true)) {
                    break;
                }
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $prevInlineNested = $this->inlineNestedProducerOpsInArgSends;
                $this->forceDeferredSiblingCallReturnSlot = true;
                $this->inlineNestedProducerOpsInArgSends = true;
                try {
                    $blockOpsBeforeProducer = \count($block->opCodes);
                    $compiledProducer = $this->compileExpr($child, $block);
                    $producerOps = array_merge(
                        $producerOps,
                        $this->drainBlockOpcodesAppendedSince($block, $blockOpsBeforeProducer),
                        $compiledProducer
                    );
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                    $this->inlineNestedProducerOpsInArgSends = $prevInlineNested;
                }
                foreach ($producerOps as $op) {
                    if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                        $timeArgSlot = (string) $op->arg1;
                        break;
                    }
                }
                break;
            }
        }
        $longitudeArgIndex = $isDateSunInfo ? 2 : 3;
        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $valueSlot = null;
            if (0 === (int) $argIndex && null !== $timeArgSlot) {
                $valueSlot = $timeArgSlot;
            } elseif (!$isDateSunInfo && 1 === (int) $argIndex && null !== $sunfuncsArgSlot) {
                $valueSlot = $sunfuncsArgSlot;
            } elseif ($longitudeArgIndex === (int) $argIndex && null !== $longitudeSlot) {
                $valueSlot = $longitudeSlot;
            }
            $literalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (null === $valueSlot && $this->isEmbeddedCallLiteralArg($literalProbe)) {
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            if (null === $valueSlot) {
                $valueSlot = $this->compileOperand($arg, $block, true);
            }
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, null, null);
        }
        $strayBlockOps = $this->drainBlockOpcodesAppendedSince($block, $blockOpsAtEntry);
        if ([] !== $strayBlockOps) {
            $producerOps = array_merge($strayBlockOps, $producerOps);
        }

        return array_merge($producerOps, $sends);
    }
}
