<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * register_shutdown / hoisted ClassConst / preg_replace_callback / array_combine
 * call-arg SEND rewires (#36387 / prior #36147).
 *
 * Extracted from {@see RewireInlineCallArgSendSlots} so gen-0 split-TU can hollow a
 * smaller Concern TU. Nested {@code var_export} dead-temp rewire lives in
 * {@see RewireVarExportNestedInlineCallArgSendSlots}. Complementary to
 * {@see RewireArithmeticBranchSubstrEnumAndSiblingMultiArgCallArgSendSlots} (#37050).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait RewireHoistedPreludePregCombineAndVarExportCallArgSendSlots
{
    /**
     * register_shutdown_function(fn(...), E::A) — Closure + enum case hoisted siblings (#5751).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('register_shutdown_function' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 2 !== \count($cfgCallOp->args)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $closureExpr = $block->orig->children[$callIndex - 2] ?? null;
        $enumExpr = $block->orig->children[$callIndex - 1] ?? null;
        if (!($closureExpr instanceof Op\Expr\Closure || $closureExpr instanceof Op\Expr\ArrowFunction)) {
            return;
        }
        if (!$enumExpr instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $closureSlot = $block->slotForOperand($closureExpr->result);
        $enumSlot = $block->slotForOperand($enumExpr->result);
        if (null === $closureSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type) {
                    $closureSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $enumSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $enumSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $closureSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = 0 === $argIndex ? $closureSlot : $enumSlot;
            ++$argIndex;
        }
    }

    private function rewireHoistedClassConstPreludeCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || [] === $cfgCallOp->args) {
            return;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        if ($this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, 0)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        if (
            null !== $callArg
            && !$this->operandsReferToSameVariable($prelude->result, $callArg)
        ) {
            // array_pad([E::A], N, E::B) / extract([...], FLAGS, Prefix::A) — immediate ClassConstFetch is not arg #0 (#8883, #16041).
            // preg_replace_callback_array([...], E::CASE) — enum prelude feeds arg #1 (#5859).
            return;
        }
        if (
            'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
            && \is_int($callIndex)
            && $callIndex >= 2
            && ($block->orig->children[$callIndex - 2] ?? null) instanceof Op\Expr\Array_
        ) {
            return;
        }
        if (null === $block->slotForOperand($prelude->result)) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $preludeSlot = $block->slotForOperand($prelude->result);
        if (null === $preludeSlot) {
            foreach ($pendingNestedProducerOps as $op) {
                if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $preludeSlot = (string) $op->arg1;
                    break;
                }
            }
        }
        if (null === $preludeSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = (string) $preludeSlot;
            }
            ++$argIndex;
        }
    }

    /**
     * preg_replace_callback_array(['/pat/' => $cb], E::CASE) — pattern Array_ is arg #0, enum case arg #1 (#5859, #9072).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewirePregReplaceCallbackArrayPatternMapArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $allArgSends = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if ('preg_replace_callback_array' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return;
        }
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $patternMap = $block->orig->children[$callIndex - 2] ?? null;
        $subjectPrelude = $block->orig->children[$callIndex - 1] ?? null;
        if (!$patternMap instanceof Op\Expr\Array_) {
            return;
        }
        if (!$subjectPrelude instanceof Op\Expr\ClassConstFetch) {
            return;
        }
        $initSlot = null;
        foreach (array_merge($block->opCodes, $allArgSends) as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initSlot = (string) $op->arg1;
            }
        }
        if (null === $initSlot) {
            $initSlot = $block->slotForOperand($patternMap->result);
            if (null !== $initSlot) {
                $initSlot = (string) $initSlot;
            }
        }
        $enumSlot = $block->slotForOperand($subjectPrelude->result);
        if (null === $enumSlot) {
            foreach ($this->compileExpr($subjectPrelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $enumSlot = $block->slotForOperand($subjectPrelude->result);
        }
        if (null === $initSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = $initSlot;
            } elseif (1 === $argIndex) {
                $send->arg1 = (string) $enumSlot;
            }
            ++$argIndex;
        }
        unset($send);
    }

    /**
     * array_combine([...], [...]) — ARG_SEND must map to sibling INIT_ARRAY slots, not recent-init (#16080, #10214, #17629).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $allArgSends
     */
    private function rewireArrayCombineInlineArgSendSlots(
        array &$outerArgSends,
        Block $block,
        array $allArgSends,
        ?string $calleeName,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || 2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $calleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('array_combine' !== $calleeLower) {
            return;
        }
        if (null === $block->orig) {
            return;
        }
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        ) as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                // array_combine(array_keys(...), [...]) — nested FuncCall feeds arg #0 (#16097).
                return;
            }
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $allArgSends);
        if (\count($initSlots) > 2) {
            // [] === array_combine([], []) — comparison lhs Array_ shares the post-call INIT_ARRAY window (#17629).
            $initSlots = \array_slice($initSlots, -2);
        }
        if (\count($initSlots) < 2) {
            return;
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (isset($initSlots[$sendOrdinal])) {
                $send->arg1 = $initSlots[$sendOrdinal];
            }
            ++$sendOrdinal;
        }
        unset($send);
    }
}
