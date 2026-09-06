<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Outer-sibling EXEC_RETURN ordinals + hoisted ConstFetch prelude slots (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineCallArgProducerSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see siblingConsumerHasTrailingByRefNamedLocal}
 * through {@see slotForImmediateConstFetchPreludeCallArg}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait OuterSiblingExecReturnAndHoistedConstFetchSlots
{
    /**
     * similar_text($a, $b, $p) / preg_match(..., $m) — trailing by-ref named local (#15476).
     */
    private function siblingConsumerHasTrailingByRefNamedLocal(Op $cfgCallOp): bool
    {
        if (
            !($cfgCallOp instanceof Op\Expr\FuncCall || $cfgCallOp instanceof Op\Expr\NsFuncCall)
            || !property_exists($cfgCallOp, 'args')
            || !\is_array($cfgCallOp->args)
        ) {
            return false;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2) {
            return false;
        }
        for ($i = $argCount - 1; $i >= 0; --$i) {
            $arg = $cfgCallOp->args[$i] ?? null;
            if (!($arg instanceof Operand)) {
                break;
            }
            if ($this->isByRefNamedCallArgExcludedFromSiblingProducerWiring($cfgCallOp, $i, $arg)) {
                return true;
            }
            if (!$this->isEmbeddedCallLiteralArg($arg)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Outer hoisted producer for a multi-arg dead inline call arg — CFG sibling order (#15488, #16280).
     *
     * @param list<Op> $cfgChildren
     */
    private function outerSiblingInlineCallArgProducerForArgIndex(
        Op $cfgCallOp,
        int $argIndex,
        int $firstSibling,
        int $callIndex,
        array $cfgChildren
    ): ?Op\Expr {
        if (!\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $callIndex, $cfgChildren);
        if (\count($outer) >= $argCount) {
            $outer = \array_slice($outer, -$argCount);
        }
        if (\count($outer) !== $argCount || $argIndex >= $argCount) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if ($callArg instanceof Operand) {
            $direct = $this->matchDirectResultInlineCallArgProducer($outer, $callArg);
            if ($direct instanceof Op\Expr) {
                return $direct;
            }
        }
        $deadInlineTempCount = 0;
        foreach ($cfgCallOp->args as $deadArg) {
            if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                ++$deadInlineTempCount;
            }
        }
        if ($deadInlineTempCount !== $argCount) {
            return null;
        }
        $leadingEmbedded = 0;
        foreach ($cfgCallOp->args as $embeddedArg) {
            if ($this->isEmbeddedCallLiteralArg($embeddedArg)) {
                ++$leadingEmbedded;
                continue;
            }
            break;
        }
        $outerOrdinal = $argIndex - $leadingEmbedded;
        if ($outerOrdinal < 0 || !isset($outer[$outerOrdinal])) {
            return null;
        }
        $outerProducer = $outer[$outerOrdinal];
        if (!$outerProducer instanceof Op\Expr) {
            return null;
        }

        return $outerProducer;
    }

    /**
     * FUNCCALL_EXEC_RETURN / operand slot for outer hoisted producer of a dead inline call arg (#16280).
     *
     * @param list<OpCode> $emitOps
     */
    private function outerSiblingInlineCallArgProducerExecReturnSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?array &$emitOps = null
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $outerProducer = $this->outerSiblingInlineCallArgProducerForArgIndex(
            $cfgCallOp,
            $argIndex,
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        if (!$outerProducer instanceof Op\Expr || null === $outerProducer->result) {
            return null;
        }
        if (null === $block->slotForOperand($outerProducer->result)) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($outerProducer, $block) as $op) {
                    if (null !== $emitOps) {
                        $emitOps[] = $op;
                    } else {
                        $block->addOpCode($op);
                    }
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
        }
        $operandSlot = $block->slotForOperand($outerProducer->result);
        if (null !== $operandSlot) {
            return (string) $operandSlot;
        }
        $execReturn = $this->slotForInlineCallArgProducerResult(
            $block,
            $outerProducer,
            $cfgCallOp,
            $block->orig->children
        );

        return null !== $execReturn ? $execReturn : null;
    }

    /**
     * Nth hoisted sibling FuncCall producer's final EXEC_RETURN slot (#15476, #15848).
     */
    private function slotForSiblingInlineFuncCallProducerExecReturnOrdinal(Block $block, int $producerOrdinal): ?int
    {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ($producerOrdinal >= \count($execReturnSlots)) {
            return null;
        }

        return $execReturnSlots[$producerOrdinal];
    }

    /**
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
        Block $block,
        int $producerOrdinal,
        array $pendingNestedProducerOps = []
    ): ?int {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ([] !== $pendingNestedProducerOps) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $execReturnSlots[] = (int) $op->arg1;
                }
            }
        }
        if ($producerOrdinal >= \count($execReturnSlots)) {
            return null;
        }

        return $execReturnSlots[$producerOrdinal];
    }

    /** Last emitted FUNCCALL_EXEC_RETURN for a literal callee name (emission order, not cfg index). */
    private function slotForLastEmittedFuncCallExecReturnByName(Block $block, string $calleeName): ?string
    {
        $needle = strtolower($calleeName);
        $slot = null;
        $pending = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initName = $this->resolveCompileTimeStringSlot((int) $op->arg1, $block);
                $pending = $needle === strtolower($initName ?? '');
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                if ($pending) {
                    $slot = (string) $op->arg1;
                }
                $pending = false;
            }
        }

        return $slot;
    }


    /**
     * var_export(..., true) — hoisted ConstFetch true may not be emitted before ARG_SEND rewire (#18164).
     */
    private function slotForVarExportHoistedReturnTruePrelude(Block $block, Op $cfgCallOp): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
            if (!$prelude instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($prelude->name);
            if ('true' !== strtolower($name ?? '')) {
                continue;
            }
            if (null === $block->slotForOperand($prelude->result)) {
                foreach ($this->compileExpr($prelude, $block) as $op) {
                    $block->addOpCode($op);
                }
            }
            $slot = $block->slotForOperand($prelude->result);

            return null !== $slot ? (string) $slot : null;
        }

        return null;
    }

    /**
     * var_export(INF, true) twice — hoisted scalar ConstFetch (INF/NAN/…) is arg #0, not prior var_export EXEC_RETURN (#18426).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForVarExportHoistedScalarConstArgZero(
        Block $block,
        Op $cfgCallOp,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block);
        while ([] !== $preludes) {
            $tail = $preludes[\count($preludes) - 1];
            if ($tail instanceof Op\Expr\ConstFetch) {
                $tailName = strtolower($this->staticNameFromOperand($tail->name) ?? '');
                if (\in_array($tailName, ['true', 'false', 'null'], true)) {
                    array_pop($preludes);
                    continue;
                }
            }
            break;
        }
        if ([] === $preludes) {
            return null;
        }
        $argZeroPrelude = $preludes[\count($preludes) - 1];
        if (
            $argZeroPrelude instanceof Op\Expr\UnaryMinus
            || $argZeroPrelude instanceof Op\Expr\UnaryPlus
        ) {
            if (null === $block->slotForOperand($argZeroPrelude->result)) {
                foreach ($this->compileExpr($argZeroPrelude, $block) as $op) {
                    if ([] !== $emitOps) {
                        $emitOps[] = $op;
                    } else {
                        $block->addOpCode($op);
                    }
                }
            }
            $slot = $block->slotForOperand($argZeroPrelude->result);

            return null !== $slot ? (string) $slot : null;
        }
        if (!$argZeroPrelude instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $name = strtolower($this->staticNameFromOperand($argZeroPrelude->name) ?? '');
        if (\in_array($name, ['true', 'false', 'null'], true)) {
            return null;
        }
        if (null === $block->slotForOperand($argZeroPrelude->result)) {
            foreach ($this->compileExpr($argZeroPrelude, $block) as $op) {
                if ([] !== $emitOps) {
                    $emitOps[] = $op;
                } else {
                    $block->addOpCode($op);
                }
            }
        }
        $slot = $block->slotForOperand($argZeroPrelude->result);
        if (null === $slot) {
            $slot = $this->slotForRecentConstFetchNamedLiteral($block, $name);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** Recent TYPE_CONST_FETCH for a global literal name (dead-temp vs hoisted-result drift, #18426). */
    private function slotForRecentConstFetchNamedLiteral(Block $block, string $literalName): ?string
    {
        $needle = strtolower($literalName);
        $i = \count($block->opCodes) - 1;
        while ($i >= 0 && OpCode::TYPE_FUNCCALL_INIT === $block->opCodes[$i]->type) {
            --$i;
        }
        for (; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg1 || null === $op->arg2) {
                continue;
            }
            $resolved = null;
            if (\is_string($op->arg2)) {
                $resolved = strtolower($op->arg2);
            } elseif (\is_int($op->arg2)) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && Variable::TYPE_STRING === $const->type) {
                    $resolved = strtolower($const->toString());
                } else {
                    $resolved = strtolower($this->resolveCompileTimeStringSlot($op->arg2, $block) ?? '');
                }
            }
            if ('' === $resolved) {
                continue;
            }

            return $needle === $resolved ? (string) $op->arg1 : null;
        }

        return null;
    }

    /**
     * var_export($it->current(), true) — MethodCall EXEC_RETURN ordinal uses legacy base (#13901, #17251).
     *
     * @param list<Op> $cfgChildren
     */
    private function slotForSiblingMethodCallProducerExecReturn(
        Block $block,
        Op\Expr $producer,
        Op $consumer,
        array $cfgChildren
    ): ?string {
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return null;
        }
        // Pair METHODCALL_INIT → EXEC_RETURN by callee name. Ordinal EXEC_RETURN indexing
        // drifts when prior stmts (loadXML) are EXEC_NORETURN but still inflate legacyBase
        // — bare $d->documentElement->replaceChild(createElement, item) (#21182).
        $paired = $this->slotForMethodOrStaticCallInitFollowingExecReturn($block, $producer);
        if (null !== $paired) {
            return $paired;
        }
        // Dead-temp createElement/item not yet on the block: ordinal EXEC_RETURN would bind a
        // prior loadXML return slot so ARG_SEND gets bool and createElement never emits (#34436).
        if (
            $producer instanceof Op\Expr\MethodCall
            && (null === $producer->result || empty($producer->result->usages))
        ) {
            return null;
        }
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
        $consumerIndex = array_search($consumer, $cfgChildren, true);
        if (!is_int($producerIndex) || !is_int($consumerIndex)) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling || $producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }
        $producerOrdinal = $this->siblingInlineFuncCallProducerOrdinal(
            $producerIndex,
            $firstSibling,
            $cfgChildren
        );
        $legacyBase = $this->execReturnOrdinalBaseBeforeSiblingInlineFuncCallChain(
            $firstSibling,
            $cfgChildren,
            $consumerIndex
        );
        $execSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
            $block,
            $legacyBase + $producerOrdinal
        );

        return null !== $execSlot ? (string) $execSlot : null;
    }

    /**
     * Hoisted ConstFetch immediately before a multi-arg consumer — map dead temps to literal slots (#16272, #13829).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForImmediateConstFetchPreludeCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        // in_array('x', g(), true) — immediate ConstFetch is strict, not haystack (#16265).
        if (
            \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['in_array', 'array_search'], true)
            && 1 === $argIndex
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $hoistedScalarSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
            $callArg,
            $block,
            $cfgCallOp,
            $argIndex
        );
        if (null !== $hoistedScalarSlot) {
            return (string) $hoistedScalarSlot;
        }

        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $immediatePrelude = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($immediatePrelude instanceof Op\Expr\ConstFetch || $immediatePrelude instanceof Op\Expr\ClassConstFetch)
            || null === $immediatePrelude->result
        ) {
            return null;
        }
        if (null === $block->slotForOperand($immediatePrelude->result)) {
            foreach ($this->compileExpr($immediatePrelude, $block) as $op) {
                if ([] !== $emitOps) {
                    $emitOps[] = $op;
                } else {
                    $block->addOpCode($op);
                }
            }
        }
        $constSlot = $block->slotForOperand($immediatePrelude->result);
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            $immediatePrelude instanceof Op\Expr\ClassConstFetch
            && null !== $callArg
            && null !== $constSlot
            && (
                $immediatePrelude->result === $callArg
                || $this->operandsReferToSameVariable($immediatePrelude->result, $callArg)
            )
        ) {
            // method_exists(I::class, 'm') after earlier stmts — hoisted ::class is arg #0 (#9486).
            return (string) $constSlot;
        }
        if (
            0 === $argIndex
            && $immediatePrelude instanceof Op\Expr\ConstFetch
            && (
                null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                    $cfgCallOp,
                    $callIndex,
                    $block->orig->children
                )
                || null !== $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes(
                    $callIndex,
                    $block->orig->children
                )
            )
        ) {
            // var_export(g(), true) / importNode($doc->documentElement, true) — trailing ConstFetch is not arg #0 (#11272, #16318).
            return null;
        }
        if (
            0 === $argIndex
            && null !== $callArg
            && $this->callArgOperandExpectsArrayProducer($callArg)
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            // array_pad([E::A], N, E::B) — immediate ClassConstFetch is pad value, not haystack (#8883).
            return null;
        }
        if (
            0 === $argIndex
            && 'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            return null;
        }
        if (
            0 === $argIndex
            && 'extract' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && !($immediatePrelude instanceof Op\Expr\Array_)
        ) {
            return null;
        }

        return null !== $constSlot ? (string) $constSlot : null;
    }
}
