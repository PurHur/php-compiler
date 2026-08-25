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
                $binaryDest = (int) $prior->arg1;
                $binaryOpResult = $block->getOperand($binaryDest);
                if (
                    $this->assignOpCanFuseInPlace($block, $prior, $key)
                    && !$this->assignOpMustSkipConcatChainPeephole($prior, $priorPrior, $op)
                ) {
                    // We can safely replace it with an assign op
                    // ??/?: deferred RHS: assign copies a pre-bound producer slot into merge dest (#11801, #16206).
                    if (null !== $op->arg3 && (int) $op->arg3 !== $binaryDest) {
                        $priorPrior = $prior;
                        $prior = $op;
                        continue;
                    }
                    $cvSlot = $op->arg2;
                    // ($g .= 'A') && … — JumpIf / (bool) cast read the concat temp; retarget to
                    // the CV after in-place fusion (#34558 / leftover #24506).
                    // Ternary arms also write the ?: result from the same temp (#34561).
                    $this->retargetShortCircuitReadersFromBinaryResult($block, $binaryDest, $cvSlot);
                    $this->retargetSiblingAssignExprFromBinaryResult($block, $binaryDest, $cvSlot, $key);
                    $prior->arg1 = $cvSlot;
                    // Compound assign ($x += 1): arg2 is the in-place lvalue — redirect both (#13083).
                    // Do not clobber additive/concat operands on ??/?: deferred RHS (#11801, #13104, #13105).
                    if (null === $prior->arg2 || (int) $prior->arg2 === $binaryDest) {
                        $prior->arg2 = $cvSlot;
                    }
                    $assignResult = $block->getOperand($op->arg1);
                    if ((int) $op->arg3 === $binaryDest || empty($assignResult->usages)) {
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
     * Fuse when the binary temp is only used by the following ASSIGN, or by that ASSIGN
     * plus short-circuit JUMPIF / (bool) cast on the same temp (`($g .= 'A') && …`, #34558),
     * or plus sibling ASSIGNs that also copy the temp (ternary CV + ?: result, #34561).
     */
    private function assignOpCanFuseInPlace(
        Block $block,
        OpCode $binary,
        int|string $assignKey
    ): bool {
        $binaryDest = (int) $binary->arg1;
        $binaryOpResult = $block->getOperand($binaryDest);
        if (null === $binaryOpResult) {
            return false;
        }
        $usageCount = count($binaryOpResult->usages);
        if (1 === $usageCount) {
            return true;
        }
        if ($usageCount < 1) {
            return false;
        }
        foreach ($block->opCodes as $laterKey => $later) {
            if ((int) $laterKey === (int) $assignKey || $later === $binary) {
                continue;
            }
            if (!$this->opReadsValueSlot($block, $later, $binaryDest)) {
                continue;
            }
            if ($this->isShortCircuitBinaryResultReader($later, $binaryDest)) {
                continue;
            }
            if ($this->isSiblingAssignOfBinaryResult($later, $binaryDest)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * JUMPIF cond or CAST_BOOL expr reading the fused binary temp (#34558).
     * php-cfg wires `&&` long-arm (bool) cast to the Concat result, not the Assign result.
     */
    private function isShortCircuitBinaryResultReader(OpCode $op, int $binaryDest): bool
    {
        if (
            OpCode::TYPE_JUMPIF === $op->type
            || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED === $op->type
        ) {
            return (int) $op->arg1 === $binaryDest;
        }
        if (OpCode::TYPE_CAST_BOOL === $op->type) {
            return (int) $op->arg2 === $binaryDest;
        }

        return false;
    }

    /** Sibling ASSIGN whose expr (arg3) is the concat temp — ternary ?: result (#34561). */
    private function isSiblingAssignOfBinaryResult(OpCode $op, int $binaryDest): bool
    {
        return OpCode::TYPE_ASSIGN === $op->type
            && null !== $op->arg3
            && (int) $op->arg3 === $binaryDest;
    }

    /** Retarget JUMPIF / CAST_BOOL from concat temp to the in-place CV (#34558). */
    private function retargetShortCircuitReadersFromBinaryResult(Block $block, int $binaryDest, $cvSlot): void
    {
        foreach ($block->opCodes as $later) {
            if (
                (
                    OpCode::TYPE_JUMPIF === $later->type
                    || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED === $later->type
                )
                && (int) $later->arg1 === $binaryDest
            ) {
                $later->arg1 = $cvSlot;
                continue;
            }
            if (OpCode::TYPE_CAST_BOOL === $later->type && (int) $later->arg2 === $binaryDest) {
                $later->arg2 = $cvSlot;
            }
        }
    }

    /**
     * Retarget sibling ASSIGN expr slots from the dead concat temp to the CV (#34561).
     * Skips the assign being fused (handled by remove / in-place rewrite).
     */
    private function retargetSiblingAssignExprFromBinaryResult(
        Block $block,
        int $binaryDest,
        $cvSlot,
        int|string $fusedAssignKey
    ): void {
        foreach ($block->opCodes as $laterKey => $later) {
            if ((int) $laterKey === (int) $fusedAssignKey) {
                continue;
            }
            if (!$this->isSiblingAssignOfBinaryResult($later, $binaryDest)) {
                continue;
            }
            $later->arg3 = $cvSlot;
        }
    }

    private function opReadsValueSlot(Block $block, OpCode $op, int $slot): bool
    {
        foreach ($block->opCodeValueScopeArgs($op) as $arg) {
            if (null !== $arg && (int) $arg === $slot) {
                return true;
            }
        }

        return false;
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
}
