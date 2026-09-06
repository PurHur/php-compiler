<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Explode / iterator_to_array / array_chunk / array_walk / closure-pair /
 * trailing-comparator inline ARG_SEND compilers (#36387 / #36403).
 *
 * Extracted from {@see CompileInlineSpecializedCallArgSends} so gen-0
 * split-TU can hollow a smaller Concern TU (complementary to ArrayPad /
 * unpack / extract / date_sun_* peers).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait ExplodeIteratorChunkWalkAndTrailingComparatorCallArgSends
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
}
