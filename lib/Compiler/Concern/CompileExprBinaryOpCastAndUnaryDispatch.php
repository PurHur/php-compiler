<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * BinaryOp / Cast / Exit / unary / empty / eval / print expression compile (#36387 / #36403).
 *
 * Extracted from {@see CompileExprDispatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Mirrors php-src Zend/zend_compile.c binary-op, cast,
 * unary, empty, eval, and print compile. Move-only; no behavior change intended.
 */
trait CompileExprBinaryOpCastAndUnaryDispatch
{
    /**
     * Lower ?? / BinaryOp (including coalesce early path).
     *
     * @return list<OpCode>
     */
    protected function compileBinaryOpExpr(Op\Expr\BinaryOp $expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            $this->compileCoalesce($expr, $block);

            return [];
        }
        if (null !== $expr->left) {
            $this->compileEmbeddedExprForOperand($expr->left, $block);
        }
        if (null !== $expr->right) {
            $this->compileEmbeddedExprForOperand($expr->right, $block);
        }
        $resultSlot = $block->inheritUndefinedLocals
            ? $block->forceFreshVarSlot($expr->result)
            : $this->compileOperand($expr->result, $block, false);
        if (!$block->closureCaptureSlotWritableForOperand($resultSlot, $expr->result)) {
            $resultSlot = $block->forceFreshVarSlot($expr->result);
        }
        $opcode = new OpCode(
            $this->getOpCodeTypeFromBinaryOp($expr),
            $resultSlot,
            null !== $expr->left ? $this->compileOperand($expr->left, $block, true) : null,
            null !== $expr->right ? $this->compileOperand($expr->right, $block, true) : null,
        );
        if ($this->isIncDecBinaryOp($expr)) {
            $opcode->isIncDec = true;
        }
        $this->assignSourceMetadata($opcode, $expr);

        return [$opcode];
    }

    /**
     * Lower Cast (with ||/&& phi seeding for (bool)).
     *
     * @return list<OpCode>
     */
    protected function compileCastExpr(Op\Expr\Cast $expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\Cast\Unset_) {
            $this->throwCompileError('The (unset) cast is no longer supported');
        }
        $line = $expr->getLine();
        // Seed jump-target ||/&& phi before lowering the cast. Prefer that seeded slot over
        // logicalShortCircuitPhiMergeSlot when the cast sits *inside* an inner && merge that
        // jumps to an outer || merge — otherwise the cast assigns the inner phi and the outer
        // phi keeps a leftover callee-name string (#25850, re-#10626).
        $seededPhiSlot = null;
        if (null !== $block->orig) {
            $seededPhiSlot = $this->seedLogicalShortCircuitPhiSlot($block->orig, $block, $expr->result);
        }
        $castResultSlot = $this->compileOperand($expr->result, $block, false);
        $ops = [new OpCode(
            $this->getOpCodeTypeFromCastOp($expr),
            $castResultSlot,
            $this->compileOperand($expr->expr, $block, true),
            $line > 0 ? $line : null,
        )];
        if ($expr instanceof Op\Expr\Cast\Bool_) {
            $phiSlot = $seededPhiSlot
                ?? $this->logicalShortCircuitJumpTargetPhiMergeSlot($block)
                ?? $this->logicalShortCircuitPhiMergeSlot($block);
            if (null !== $phiSlot) {
                if ($block->isNamedVariableSlot($phiSlot)) {
                    $phiSlot = $block->forceFreshVarSlot($expr->result, $phiSlot);
                    if (null !== $block->orig) {
                        $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                        if (null !== $mergeCfg) {
                            $this->ternaryMergePhiRhsSlots[$mergeCfg] = $phiSlot;
                        }
                    }
                }
                if ($castResultSlot !== $phiSlot) {
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $phiSlot,
                        $phiSlot,
                        $castResultSlot
                    );
                }
            }
        }

        return $ops;
    }

    /**
     * Lower Exit_ (die/exit).
     *
     * @return list<OpCode>
     */
    protected function compileExitExpr(Op\Expr\Exit_ $expr, Block $block): array
    {
        $exitExpr = null !== $expr->expr
            ? $this->compileOperand($expr->expr, $block, true)
            : null;
        $resultSlot = null;
        if ([] !== $expr->result->usages || $block->callResultFeedsReturn($expr->result)) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
        }

        $exitOp = new OpCode(
            OpCode::TYPE_EXIT,
            $resultSlot,
            $exitExpr,
            max(0, $expr->getLine())
        );
        if (null !== $expr->message) {
            $exitOp->exitMessageSlot = $this->compileOperand($expr->message, $block, true);
        }

        return [$exitOp];
    }

    /**
     * Lower UnaryPlus / UnaryMinus (with literal fold).
     *
     * @return list<OpCode>
     */
    protected function compileUnaryPlusMinusExpr(Op\Expr $expr, Block $block): array
    {
        $foldedUnaryLiteral = $this->tryFoldUnaryLiteralDefault($expr);
        if (null !== $foldedUnaryLiteral) {
            $block->registerConstant($expr->result, $foldedUnaryLiteral);

            return [];
        }

        return [new OpCode(
            $this->getOpCodeTypeFromUnaryOp($expr),
            $this->compileOperand($expr->result, $block, false),
            $this->compileUnaryExprReadOperand($expr, $block)
        )];
    }

    /**
     * Lower BitwiseNot / BooleanNot / Clone_.
     *
     * @return list<OpCode>
     */
    protected function compileBitwiseBooleanNotOrCloneExpr(Op\Expr $expr, Block $block): array
    {
        return [new OpCode(
            $this->getOpCodeTypeFromUnaryOp($expr),
            $this->compileOperand($expr->result, $block, false),
            $this->compileUnaryExprReadOperand($expr, $block)
        )];
    }

    /**
     * Lower empty() (nullsafe / property / static / dim / unary).
     *
     * @return list<OpCode>
     */
    protected function compileEmptyExpr(Op\Expr\Empty_ $expr, Block $block): array
    {
        if ([] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($expr, $block))) {
            $this->compileEmptyNullsafePropertyFetchChain($nullsafeChain, $expr, $block);

            return [];
        }
        $emptyOperand = $this->recoverEmptyExprOperand($expr, $block)
            ?? $this->unaryExprOperandForRead($expr, $block);
        $propFetch = null !== $emptyOperand
            ? $this->findCoalescePropertyFetch($emptyOperand, $block)
            : null;
        if (null === $propFetch && null !== $emptyOperand) {
            $propFetch = $this->unwrapPropertyFetch($emptyOperand);
        }
        if (null !== $propFetch) {
            return [new OpCode(
                OpCode::TYPE_EMPTY_OBJECT_PROPERTY,
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($propFetch->var, $block, true),
                $this->compileOperand($propFetch->name, $block, true),
            )];
        }
        $staticPropFetch = null !== $emptyOperand
            ? $this->findCoalesceStaticPropertyFetch($emptyOperand, $block)
            : null;
        if (null === $staticPropFetch && null !== $emptyOperand) {
            $staticPropFetch = $this->unwrapStaticPropertyFetch($emptyOperand);
        }
        if (null !== $staticPropFetch) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
            [$classSlot, $nameSlot] = $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block);

            return [new OpCode(
                OpCode::TYPE_EMPTY_STATIC_PROPERTY,
                $resultSlot,
                $classSlot,
                $nameSlot
            )];
        }
        $dimFetch = null !== $emptyOperand
            ? $this->findCoalesceArrayDimFetch($emptyOperand, $block)
            : null;
        if (null !== $dimFetch) {
            $chain = $this->collectArrayDimFetchChain($dimFetch, $block);
            foreach ($chain as $chainFetch) {
                $this->rejectArrayEmptyOffsetRead($chainFetch, $block);
            }
            $resultSlot = $this->compileOperand($expr->result, $block, false);
            [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefix($chain, $block);
            $lastFetch = $chain[count($chain) - 1];
            $dimSlot = null !== $lastFetch->dim
                ? $this->compileOperand($lastFetch->dim, $block, true)
                : null;
            if (null !== $containerSlot) {
                $prefixOps[] = new OpCode(
                    OpCode::TYPE_EMPTY_DIMENSION,
                    $resultSlot,
                    $containerSlot,
                    $dimSlot
                );

                return $prefixOps;
            }
        }

        $op = new OpCode(
            OpCode::TYPE_EMPTY,
            $this->compileOperand($expr->result, $block, false),
            $this->compileUnaryExprReadOperand($expr, $block)
        );
        $this->assignSourceMetadata($op, $expr);

        return [$op];
    }

    /**
     * Lower eval().
     *
     * @return list<OpCode>
     */
    protected function compileEvalExpr(Op\Expr\Eval_ $expr, Block $block): array
    {
        $evalOp = new OpCode(
            $this->getOpCodeTypeFromUnaryOp($expr),
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true)
        );
        // Call-site line for Zend eval __FILE__ / fatals: parent(line) : eval()'d code (#25809, #4410).
        $this->assignSourceMetadata($evalOp, $expr);

        return [$evalOp];
    }

    /**
     * Lower print.
     *
     * @return list<OpCode>
     */
    protected function compilePrintExpr(Op\Expr\Print_ $expr, Block $block): array
    {
        $line = $expr->getLine();

        return [new OpCode(
            $this->getOpCodeTypeFromUnaryOp($expr),
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $line > 0 ? $line : null
        )];
    }
}
