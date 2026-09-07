<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Arithmetic-branch / substr+sprintf / enum-prefix / nested MethodCall ClassConst SEND rewires
 * (#36387 / #36403).
 *
 * Extracted from {@see RewireInlineCallArgSendSlots} so gen-0 split-TU can hollow
 * a smaller Concern TU (complementary to Bitmask / register_shutdown / var_export
 * peers left in the parent trait).
 *
 * Covers {@see rewireInlineArithmeticBranchCallArgSendSlots},
 * {@see rewireSubstrNestedSprintfArgSendSlots},
 * {@see rewireNestedFuncCallEnumPrefixCallArgSendSlots}, and
 * {@see rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots}.
 * Sibling multi-arg + hoisted ConstFetch prelude guards live in
 * {@see RewireSiblingMultiArgInlineCallArgSendSlots}.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* / adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait RewireArithmeticBranchSubstrEnumAndSiblingMultiArgCallArgSendSlots
{
    /**
     * decoct(fileperms($f) & 0777) on CFG branch blocks — ARG_SEND must use the AND dest (#15902).
     *
     * Only when the arithmetic producer immediately precedes this call. A sibling
     * `get(Box::Y)+1` leaves TYPE_PLUS in the merge block; the next `get(Box::Z)` must
     * keep its ClassConstFetch slot, not steal the plus result (#26990).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireInlineArithmeticBranchCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if ((!$block->inheritUndefinedLocals && !$block->arrowAutoCapture) || null === $cfgCallOp) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            1 !== \count($cfgCallOp->args ?? [])
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, 0)
        ) {
            return;
        }
        // Intervening ClassConstFetch/ConstFetch before this call — not decoct(expr&mask) (#26990).
        if (!$this->cfgCallImmediatelyConsumesPrecedingArithmetic($block, $cfgCallOp)) {
            return;
        }
        $dest = $this->slotForRecentInlineArithmeticCallArg(
            $block,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        if (null === $dest) {
            return;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND === $send->type) {
                $send->arg1 = $dest;
            }
        }
    }

    /**
     * True when the CFG child immediately before $cfgCallOp is an arithmetic/bitwise
     * producer feeding that call (#15902 decoct; negative for #26990 ClassConstFetch).
     */
    private function cfgCallImmediatelyConsumesPrecedingArithmetic(Block $block, Op $cfgCallOp): bool
    {
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1 || null === $block->orig) {
            return false;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;

        return $this->isArithmeticInlineCallArgProducer($prev);
    }

    /**
     * substr(sprintf('%o', fileperms($path)), -N) after stmt-level calls — haystack ARG_SEND (#16451, #16480).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireSubstrNestedSprintfArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 2) {
            return;
        }
        if ('substr' !== strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex)) {
            return;
        }
        $cfgChildren = $block->orig->children;
        $nestedHaystack = $this->substrNestedHaystackFuncCallAtUnaryMinusPattern(
            $cfgCallOp,
            $callIndex,
            $cfgChildren
        );
        if (null === $nestedHaystack) {
            return;
        }
        $haystackSlot = $this->slotForSubstrNestedHaystackFuncCallExecReturn(
            $block,
            $nestedHaystack[0],
            $nestedHaystack[1],
            $cfgChildren
        );
        if (null === $haystackSlot) {
            return;
        }
        $offsetOp = $cfgChildren[$callIndex - 1] ?? null;
        $argSendIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argSendIndex) {
                $send->arg1 = $haystackSlot;
            } elseif (
                1 === $argSendIndex
                && $offsetOp instanceof Op\Expr\UnaryMinus
            ) {
                $inner = $offsetOp->expr ?? null;
                if ($inner instanceof Operand\Literal && is_numeric($inner->value)) {
                    $negated = is_int($inner->value) ? -(int) $inner->value : -(float) $inner->value;
                    $send->arg1 = (string) $this->freshLiteralConstantSlot(
                        new Operand\Literal($negated),
                        $block
                    );
                }
            }
            ++$argSendIndex;
        }
    }

    /**
     * tempnam(sys_get_temp_dir(), E::A) — nested FuncCall EXEC_RETURN is arg #0; enum ClassConstFetch is arg #1 (#10303, #16558).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireNestedFuncCallEnumPrefixCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $block->orig || 2 !== \count($cfgCallOp->args ?? [])) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $nestedCall = $block->orig->children[$callIndex - 2] ?? null;
        $enumFetch = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($nestedCall instanceof Op\Expr\FuncCall || $nestedCall instanceof Op\Expr\NsFuncCall)
            || !$enumFetch instanceof Op\Expr\ClassConstFetch
            || !$this->nestedFuncCallProducerSeparatedBySkippablePreludesOnly(
                $callIndex - 2,
                $callIndex,
                $block->orig->children
            )
        ) {
            return;
        }
        $execReturnCount = $block->funccallExecReturnCount();
        foreach ($pendingNestedProducerOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                ++$execReturnCount;
            }
        }
        $execSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinalWithPending(
            $block,
            max(0, $execReturnCount - 1),
            $pendingNestedProducerOps
        );
        if (null === $execSlot) {
            $execSlot = $block->slotForOperand($nestedCall->result);
        }
        $enumSlot = $block->slotForOperand($enumFetch->result);
        if (null === $enumSlot) {
            foreach ($this->compileExpr($enumFetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $enumSlot = $block->slotForOperand($enumFetch->result);
        }
        if (null === $execSlot || null === $enumSlot) {
            return;
        }
        $argIndex = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argIndex) {
                $send->arg1 = (string) $execSlot;
            } elseif (1 === $argIndex) {
                $send->arg1 = (string) $enumSlot;
            }
            ++$argIndex;
        }
    }

    /**
     * count($ref->getAttributes(...)) — wire MethodCall EXEC_RETURN into the outer ARG_SEND (#21867, #22693).
     *
     * Covers filtered getAttributes(Foo::class) (hoisted ClassConstFetch prelude) and bare
     * getAttributes() (no prelude). Without this, ARG_SEND keeps an earlier dead temp (often a
     * prior ::class string / null) and count() TypeErrors on null.
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $pendingNestedProducerOps
     */
    private function rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp,
        array $pendingNestedProducerOps = []
    ): void {
        if (null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 1 !== \count($cfgCallOp->args)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $producer = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$producer instanceof Op\Expr\MethodCall
            && !$producer instanceof Op\Expr\StaticCall
        ) {
            return;
        }
        // Filtered form: ClassConstFetch immediately before MethodCall feeds the method arg.
        // If the outer dead temp is that ClassConstFetch result, wiring is already correct.
        if ($callIndex >= 2) {
            $prelude = $block->orig->children[$callIndex - 2] ?? null;
            if (
                $prelude instanceof Op\Expr\ClassConstFetch
                && $callArg instanceof Operand
                && $this->operandsReferToSameVariable($prelude->result, $callArg)
            ) {
                return;
            }
        }
        $execSlot = $this->slotForMethodOrStaticCallInitFollowingExecReturn(
            $block,
            $producer,
            $pendingNestedProducerOps
        );
        if (null === $execSlot) {
            $execSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                $block,
                $producer,
                $cfgCallOp,
                $block->orig->children
            );
        }
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = (string) $execSlot;

            return;
        }
    }

}
