<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Bitmask / nested-file / var_export-flag inline call-arg send rewires (#36387 / prior #36147).
 *
 * Extracted from {@see RewireInlineCallArgSendSlots} so gen-0 split-TU can hollow a smaller
 * Concern TU (`rewireInlineBitmaskTrailingCallArgSendSlots` → `slotForInlineExprResultInProducerOps`).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as RewireInlineCallArgSendSlots).
 */
trait RewireInlineBitmaskNestedFileAndVarExportFlagCallArgSendSlots
{
    /**
     * file_put_contents($f, 'a', FILE_APPEND | LOCK_EX) — trailing dead-temp arg must use BitwiseOr dest (#18523).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireInlineBitmaskTrailingCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if ($immediate instanceof Op\Expr\Assign) {
            $hoistedRhs = $callIndex > 1 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
            if (
                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                $immediate = $hoistedRhs;
            } else {
                $immediate = $immediate->expr;
            }
        }
        if (
            !$immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            && !$immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            && !$immediate instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            return;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidateArg) {
            if (null !== $candidateArg && !$this->isEmbeddedCallLiteralArg($candidateArg)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $trailingArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
        if (null === $trailingArgIndex) {
            return;
        }
        $trailingArg = $cfgCallOp->args[$trailingArgIndex] ?? null;
        $assignInCallBitmask = ($block->orig->children[$callIndex - 1] ?? null) instanceof Op\Expr\Assign;
        if (
            !$assignInCallBitmask
            && (
                !$this->callArgIsDeadInlineTemporary($trailingArg)
                || $this->callArgOperandExpectsArrayProducer($trailingArg)
            )
        ) {
            return;
        }
        if ($assignInCallBitmask && $this->callArgOperandExpectsArrayProducer($trailingArg)) {
            return;
        }
        $bitmaskSlot = $this->slotForHoistedAssignInCallNamedDest($block, $cfgCallOp);
        if (null === $bitmaskSlot && null !== $immediate->result) {
            $bitmaskSlot = $block->slotForOperand($immediate->result);
            if (null !== $bitmaskSlot) {
                $bitmaskSlot = (string) $bitmaskSlot;
            }
        }
        if (null === $bitmaskSlot) {
            foreach (array_reverse(array_merge($nestedProducerOps, $block->opCodes, $outerArgSends)) as $op) {
                if (
                    OpCode::TYPE_BITWISE_OR === $op->type
                    || OpCode::TYPE_BITWISE_AND === $op->type
                    || OpCode::TYPE_BITWISE_XOR === $op->type
                ) {
                    if (null !== $op->arg1) {
                        $bitmaskSlot = (string) $op->arg1;
                        break;
                    }
                }
            }
        }
        if (null === $bitmaskSlot) {
            return;
        }
        if ($assignInCallBitmask) {
            for ($i = \count($outerArgSends) - 1; $i >= 0; --$i) {
                $send = $outerArgSends[$i];
                if (OpCode::TYPE_ARG_SEND !== $send->type) {
                    continue;
                }
                $send->arg1 = $bitmaskSlot;

                return;
            }

            return;
        }
        $argSendOrdinal = 0;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if ($argSendOrdinal === $trailingArgIndex) {
                $send->arg1 = $bitmaskSlot;

                return;
            }
            ++$argSendOrdinal;
        }
    }

    /**
     * is_array(file(..., FILE_* | FILE_*)) / count(file(...)) — arg #0 must use adjacent FUNCCALL_EXEC_RETURN,
     * not the hoisted bitmask OR slot (#10474).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['is_array', 'count', 'array_keys'], true) || null === $cfgCallOp || null === $block->orig) {
            return;
        }
        if (!\is_array($cfgCallOp->args ?? null) || 1 !== \count($cfgCallOp->args)) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $adjacentIndex = $callIndex - 1;
        while ($adjacentIndex >= 0) {
            $skip = $block->orig->children[$adjacentIndex] ?? null;
            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                --$adjacentIndex;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($skip)) {
                --$adjacentIndex;
                continue;
            }
            break;
        }
        $adjacent = $block->orig->children[$adjacentIndex] ?? null;
        if (!(
            $adjacent instanceof Op\Expr\FuncCall
            || $adjacent instanceof Op\Expr\NsFuncCall
            || $adjacent instanceof Op\Expr\MethodCall
            || $adjacent instanceof Op\Expr\StaticCall
        )) {
            return;
        }
        $execSlot = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
            $block,
            $adjacent,
            $cfgCallOp,
            $block->orig->children
        );
        if (null === $execSlot) {
            $execSlot = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($nestedProducerOps)
                ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
        }
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND === $send->type) {
                $send->arg1 = (string) $execSlot;
                break;
            }
        }
        unset($send);
    }

    private function isInlineCallArgProducerInitOpcode(OpCode $op): bool
    {
        return OpCode::TYPE_FUNCCALL_INIT === $op->type
            || OpCode::TYPE_STATICCALL_INIT === $op->type
            || OpCode::TYPE_METHODCALL_INIT === $op->type;
    }

    private function isInlineCallArgProducerExecOpcode(OpCode $op): bool
    {
        return OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
            || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type;
    }

    /**
     * json_encode($s, JSON_HEX_* | …) — arg #0 is a named local; bitmask preludes must not replace value ARG_SEND (#10956).
     *
     * @param list<OpCode> $outerArgSends
     */
    private function rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots(
        array &$outerArgSends,
        Block $block,
        ?Op $cfgCallOp
    ): void {
        if (null === $cfgCallOp || null === $block->orig || \count($cfgCallOp->args ?? []) < 2) {
            return;
        }
        $valueArg = $cfgCallOp->args[0] ?? null;
        if (!$valueArg instanceof Operand || $this->callArgIsDeadInlineTemporary($valueArg)) {
            return;
        }
        if (!$this->cfgCallPrecededByInlineBitmaskProducer($cfgCallOp, $block)) {
            return;
        }
        $namedSlot = $this->namedLocalCallArgSlotIfBound($valueArg, $block, $cfgCallOp, 0)
            ?? $this->slotForNamedLocalFromAssignVarOperand($valueArg, $block);
        if (null === $namedSlot) {
            $operandSlot = $block->slotForOperand($valueArg);
            if (null === $operandSlot) {
                return;
            }
            $namedSlot = (int) $operandSlot;
        }
        $wired = (string) $this->finalizeOperandSlotForAccess($block, (int) $namedSlot, true);
        $argSendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if (0 === $argSendOrdinal) {
                $send->arg1 = $wired;

                return;
            }
            ++$argSendOrdinal;
        }
        unset($send);
    }

    private function cfgCallPrecededByInlineBitmaskProducer(Op $cfgCallOp, Block $block): bool
    {
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if ($immediate instanceof Op\Expr\Assign) {
            $hoistedRhs = $callIndex > 1 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
            if (
                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                $immediate = $hoistedRhs;
            } else {
                $immediate = $immediate->expr;
            }
        }

        return $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor;
    }

    /**
     * is_array(file(...)) / count(file(...)) — ARG_SEND must use nested file() EXEC_RETURN, not bitmask OR (#10474).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireIsArrayNestedFileCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['is_array', 'count'], true) || null === $cfgCallOp || null === $block->orig) {
            return;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if (!$immediate instanceof Op\Expr\FuncCall && !$immediate instanceof Op\Expr\NsFuncCall) {
            return;
        }
        if ('file' !== strtolower($this->resolveCfgFuncCallName($immediate) ?? '')) {
            return;
        }
        $execSlot = $this->slotForLastInlineFuncCallExecReturn($block, $nestedProducerOps);
        if (null === $execSlot) {
            return;
        }
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            if ((string) $send->arg1 !== (string) $execSlot) {
                $send->arg1 = $execSlot;
            }
            break;
        }
        unset($send);
    }

    /**
     * var_export($expr !== false, true) — arg #0 is compare result, arg #1 is return flag only (#17250, #17277).
     *
     * @param list<OpCode> $outerArgSends
     * @param list<OpCode> $nestedProducerOps
     */
    private function rewireVarExportComparisonReturnFlagCallArgSendSlots(
        array &$outerArgSends,
        array $nestedProducerOps,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): void {
        if ('var_export' !== strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return;
        }
        if (
            null === $cfgCallOp
            || null === $block->orig
            || !\is_array($cfgCallOp->args ?? null)
            || \count($cfgCallOp->args) < 2
        ) {
            return;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return;
        }
        $returnFlagExpr = $block->orig->children[$callIndex - 1] ?? null;
        if (!$this->isHoistedScalarConstFetchImmediatelyBeforeCall($returnFlagExpr)) {
            return;
        }
        $comparisonExpr = $block->orig->children[$callIndex - 2] ?? null;
        if (
            !$this->isComparisonInlineCallArgProducer($comparisonExpr)
            || !$comparisonExpr instanceof Op\Expr
            || null === $comparisonExpr->result
            || !$returnFlagExpr instanceof Op\Expr\ConstFetch
            || null === $returnFlagExpr->result
        ) {
            return;
        }
        $comparisonSlot = $this->slotForInlineExprResultInProducerOps(
            $comparisonExpr,
            $block,
            $nestedProducerOps
        );
        $returnSlot = $this->slotForInlineExprResultInProducerOps(
            $returnFlagExpr,
            $block,
            $nestedProducerOps
        );
        if (null === $comparisonSlot || null === $returnSlot) {
            return;
        }
        $sendOrdinal = 0;
        foreach ($outerArgSends as &$send) {
            if (OpCode::TYPE_ARG_SEND !== $send->type) {
                continue;
            }
            $send->arg1 = 0 === $sendOrdinal ? $comparisonSlot : $returnSlot;
            ++$sendOrdinal;
            if ($sendOrdinal >= 2) {
                break;
            }
        }
        unset($send);
    }

    /**
     * @param list<OpCode> $producerOps
     */
    private function slotForInlineExprResultInProducerOps(
        Op\Expr $expr,
        Block $block,
        array $producerOps
    ): ?string {
        $mapped = $block->slotForOperand($expr->result);
        if (null !== $mapped) {
            return (string) $mapped;
        }
        $leftSlot = null;
        $rightSlot = null;
        if ($expr instanceof Op\Expr\BinaryOp) {
            $leftSlot = null !== $expr->left ? $block->slotForOperand($expr->left) : null;
            $rightSlot = null !== $expr->right ? $block->slotForOperand($expr->right) : null;
        }
        foreach ($producerOps as $op) {
            if ($expr instanceof Op\Expr\ConstFetch && OpCode::TYPE_CONST_FETCH === $op->type) {
                if ((string) $op->arg1 === (string) $block->slotForOperand($expr->result)) {
                    return (string) $op->arg1;
                }
            }
            if ($this->isComparisonInlineCallArgProducer($expr)) {
                $compareTypes = [
                    OpCode::TYPE_IDENTICAL,
                    OpCode::TYPE_NOT_IDENTICAL,
                    OpCode::TYPE_EQUAL,
                    OpCode::TYPE_NOT_EQUAL,
                    OpCode::TYPE_SPACESHIP,
                    OpCode::TYPE_SMALLER,
                    OpCode::TYPE_GREATER,
                    OpCode::TYPE_SMALLER_OR_EQUAL,
                    OpCode::TYPE_GREATER_OR_EQUAL,
                ];
                if (!\in_array($op->type, $compareTypes, true)) {
                    continue;
                }
                if (null !== $leftSlot && (string) $op->arg2 !== (string) $leftSlot) {
                    continue;
                }
                if (null !== $rightSlot && (string) $op->arg3 !== (string) $rightSlot) {
                    continue;
                }

                return (string) $op->arg1;
            }
        }

        return null;
    }
}
