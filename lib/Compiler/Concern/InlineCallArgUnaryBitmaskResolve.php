<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * UnaryMinus/UnaryPlus and trailing bitmask/scalar-option inline call-arg
 * resolvers (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgCompileTimeFold} after
 * {@see InlineCallArgConcatArithmeticFold} so gen-0 split-TU can hollow a
 * smaller Concern TU. Mirrors php-src Zend/zend_compile.c SEND_* lowering for
 * unary/bitwise option preludes (`zend_compile_expr` UnaryMinus / BitwiseOr
 * paths; #13387 / #18523 / #19735). Helpers
 * {@see unaryLiteralFeedsSiblingArrayDimFetchDim} /
 * {@see unaryLiteralProducerForHoistedCallArg} remain in
 * InlineCallArgConcatArithmeticFold. Move-only; no new C ABI.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgCompileTimeFold).
 */
trait InlineCallArgUnaryBitmaskResolve
{
    /**
     * Fold or lower UnaryMinus/UnaryPlus call args before ARG_SEND (#13387, zend_operators.c concat chains).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveUnaryLiteralCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && $this->unaryLiteralFeedsSiblingArrayDimFetchDim($cfgCallOp, $block)
        ) {
            return null;
        }
        $unaryRoot = $this->unwrapOperandChain($arg);
        if (!$unaryRoot instanceof Op\Expr\UnaryMinus && !$unaryRoot instanceof Op\Expr\UnaryPlus) {
            $unaryRoot = $this->unaryLiteralProducerForHoistedCallArg($cfgCallOp, $argIndex, $block, $arg);
        }
        if (!$unaryRoot instanceof Op\Expr\UnaryMinus && !$unaryRoot instanceof Op\Expr\UnaryPlus) {
            return null;
        }
        $vm = $this->tryFoldUnaryLiteralDefault($unaryRoot);
        if (null !== $vm) {
            return $block->registerConstant($unaryRoot->result, $vm);
        }
        if (null === $block->slotForOperand($unaryRoot->result)) {
            foreach ($this->compileExpr($unaryRoot, $block) as $op) {
                $emitOps[] = $op;
            }
        }

        return $block->slotForOperand($unaryRoot->result);
    }

    /**
     * Trailing scalar/flag option prelude for multi-arg call/ctor (#18523, #19735, #19738).
     * file_put_contents(..., FILE_APPEND|LOCK_EX), new C(new X, new Y, 1+2), new C(..., -1), (int) casts.
     * Dead arg temp must use the prelude result, not prior New_/call return; only the trailing arg binds.
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveInlineBitmaskCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $assignProducer = null;
        $last = $producers[\count($producers) - 1] ?? null;
        if ($last instanceof Op\Expr\Assign) {
            $assignProducer = $last;
            $last = $last->expr;
        }
        // ConstFetch/ClassConstFetch options use dedicated remaps; bind arithmetic/bitwise/unary/cast here.
        $isScalarOptionPrelude = $this->isArithmeticInlineCallArgProducer($last)
            || $last instanceof Op\Expr\UnaryMinus
            || $last instanceof Op\Expr\UnaryPlus
            || $last instanceof Op\Expr\BitwiseNot
            || $last instanceof Op\Expr\Cast;
        if (!$isScalarOptionPrelude) {
            return null;
        }
        if ($argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($this->compileExpr($last, $block) as $op) {
                $emitOps[] = $op;
            }
        }

        return $block->slotForOperand($last->result);
    }

    /** Last call arg index that is not an embedded literal (e.g. json_encode($v, JSON_* | JSON_*)). */
    private function trailingNonEmbeddedCallArgIndex(Op $cfgCallOp): int
    {
        if (!\is_array($cfgCallOp->args ?? null)) {
            return -1;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidateArg) {
            if (null !== $candidateArg && !$this->isEmbeddedCallLiteralArg($candidateArg)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }

        return $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? -1;
    }

    /** file_put_contents($f, 'a', FILE_APPEND | LOCK_EX) — skip adjacent FuncCall rewire (#18523). */
    private function immediatePredecessorIsInlineBitmaskProducer(Op $cfgCallOp, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;

        return $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor;
    }
}
