<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM\Optimizer;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Optimizer;

class AssignOp extends Optimizer
{
    const CANDIDATE_OPS = [
        OpCode::TYPE_CONCAT,
        OpCode::TYPE_PLUS,
        OpCode::TYPE_MINUS,
        OpCode::TYPE_MUL,
        OpCode::TYPE_DIV,
        OpCode::TYPE_MODULO,
        OpCode::TYPE_POW,
        OpCode::TYPE_BITWISE_AND,
        OpCode::TYPE_BITWISE_OR,
        OpCode::TYPE_BITWISE_XOR,
        OpCode::TYPE_SHIFT_LEFT,
        OpCode::TYPE_SHIFT_RIGHT,
    ];

    public function optimize(Block $block, ?\SplObjectStorage $seen = null): void
    {
        $seen = $seen ?? new \SplObjectStorage();
        if ($seen->contains($block)) {
            return;
        }
        $seen->attach($block);
        $prior = null;
        $priorPrior = null;
        $toRemove = [];
        foreach ($block->opCodes as $key => $op) {
            if ($op->type === OpCode::TYPE_ASSIGN && null !== $prior && in_array($prior->type, self::CANDIDATE_OPS, true)) {
                // replace
                $binaryOpResult = $block->getOperand($prior->arg1);
                if (
                    $this->binaryResultUsagesAllowAssignOpFusion($binaryOpResult)
                    && !$this->assignOpMustSkipConcatChainPeephole($prior, $priorPrior, $op)
                ) {
                    // We can safely replace it with an assign op
                    $binaryDest = $prior->arg1;
                    // ??/?: deferred RHS: assign copies a pre-bound producer slot into merge dest (#11801, #16206).
                    if (null !== $op->arg3 && (int) $op->arg3 !== (int) $binaryDest) {
                        $priorPrior = $prior;
                        $prior = $op;
                        continue;
                    }
                    $lvalue = $op->arg2;
                    $prior->arg1 = $lvalue;
                    // Compound assign ($x += 1): arg2 is the in-place lvalue — redirect both (#13083).
                    // Do not clobber additive/concat operands on ??/?: deferred RHS (#11801, #13104, #13105).
                    if (null === $prior->arg2 || (int) $prior->arg2 === (int) $binaryDest) {
                        $prior->arg2 = $lvalue;
                    }
                    // `($g .= 'A') && …`: JumpIf / Cast_Bool may still read the concat temp;
                    // retarget them to the CV after in-place fusion (#34558).
                    $this->retargetCondReadersFromTempToLvalue($block, (int) $binaryDest, (int) $lvalue);
                    $assignResult = $block->getOperand($op->arg1);
                    if ((int) $op->arg3 === (int) $binaryDest || empty($assignResult->usages)) {
                        // Binary result was only copied into the assign dest; redirect makes assign dead (#11801).
                        $toRemove[] = $key;
                    } else {
                        // We still need the assign, since we're using the result
                        $op->arg2 = $op->arg1;
                    }
                }
            }
            $priorPrior = $prior;
            $prior = $op;
            if (null !== $op->block1) {
                $this->optimize($op->block1, $seen);
            }
            if (null !== $op->block2) {
                $this->optimize($op->block2, $seen);
            }
        }

        if (! empty($toRemove)) {
            foreach ($toRemove as $key) {
                unset($block->opCodes[$key]);
            }
            $block->opCodes = array_values($block->opCodes);
            $block->nOpCodes = \count($block->opCodes);
        }
    }

    /**
     * Multi-part encapsed ConcatList lowers as CONCAT chain + assign; redirecting the
     * trailing in-place append to the assign dest drops the prior chain result (#13466).
     */
    private function assignOpMustSkipConcatChainPeephole(OpCode $prior, ?OpCode $priorPrior, OpCode $assign): bool
    {
        if (OpCode::TYPE_CONCAT !== $prior->type) {
            return false;
        }
        if (null === $prior->arg1 || null === $prior->arg2 || (int) $prior->arg2 !== (int) $prior->arg1) {
            return false;
        }
        if (null === $assign->arg2 || (int) $prior->arg1 === (int) $assign->arg2) {
            return false;
        }
        if (null === $priorPrior || OpCode::TYPE_CONCAT !== $priorPrior->type) {
            return false;
        }

        return null !== $priorPrior->arg1 && (int) $priorPrior->arg1 === (int) $prior->arg1;
    }

    /**
     * Fuse Concat+Assign when the concat temp is only read by that Assign and optional
     * JumpIf / Cast_Bool readers (`($g .= 'A') && …`). AOT mis-handles the
     * temp+assign+JumpIf shape (CV ends up empty / overwritten); in-place
     * CONCAT($g,$g,lit) + JumpIf($g) matches Zend (#34558, Zend/zend_compile.c
     * ZEND_ASSIGN_CONCAT). Cast_Bool on the concat temp is the same shape on the
     * long arm of `&&` (bool result phi).
     */
    private function binaryResultUsagesAllowAssignOpFusion(\PHPCfg\Operand $binaryOpResult): bool
    {
        // Original gate: concat/result temp has a single reader (the following Assign).
        if (1 === \count($binaryOpResult->usages)) {
            return true;
        }
        // `($g .= 'A') && …`: JumpIf / Cast_Bool also read the concat temp (#34558).
        $assignCount = 0;
        foreach ($binaryOpResult->usages as $usage) {
            if ($usage instanceof \PHPCfg\Op\Expr\Assign) {
                ++$assignCount;
                continue;
            }
            if ($usage instanceof \PHPCfg\Op\Stmt\JumpIf) {
                continue;
            }
            if ($usage instanceof \PHPCfg\Op\Expr\Cast\Bool_) {
                continue;
            }

            return false;
        }

        return 1 === $assignCount;
    }

    /**
     * After in-place fusion, JumpIf / Cast_Bool must read the CV — not the dead concat temp (#34558).
     */
    private function retargetCondReadersFromTempToLvalue(Block $block, int $tempSlot, int $lvalueSlot): void
    {
        if ($tempSlot === $lvalueSlot) {
            return;
        }
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_JUMPIF === $op->type
                && null !== $op->arg1
                && (int) $op->arg1 === $tempSlot
            ) {
                $op->arg1 = $lvalueSlot;
            }
            if (
                OpCode::TYPE_CAST_BOOL === $op->type
                && null !== $op->arg2
                && (int) $op->arg2 === $tempSlot
            ) {
                $op->arg2 = $lvalueSlot;
            }
        }
    }
}
