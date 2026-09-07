<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\Block;

/**
 * Ternary (`?:`) jump/merge-target detectors (#36387 / #36403).
 *
 * Companions: {@see TernaryMergeAndLogicalShortCircuitSlots},
 * {@see TernaryMergeVarSlotCompile}, {@see TernaryNullableParamNullAndUnpackHelpers}.
 * JumpIf / merge-target detect stays here so gen-0 split-TU can hollow a smaller
 * Concern TU. Mirrors php-src Zend/zend_compile.c ternary compile — move-only;
 * no behavior change intended.
 */

trait TernaryAndLogicalShortCircuit
{
    /**
     * @return list<CfgBlock>
     */
    private function ternaryMergeTargets(CfgBlock $branchCfg): array
    {
        $merges = [];
        foreach ($branchCfg->children as $child) {
            if (!$child instanceof Op\Stmt\Jump) {
                continue;
            }
            $merge = $child->target;
            if (\count($merge->parents) >= 2) {
                $merges[] = $merge;
            }
        }

        return $merges;
    }

    /** CFG block jumped to at the end of a ?: / if branch (may have one parent while lowering). */
    private function branchJumpMergeTarget(CfgBlock $branchCfg): ?CfgBlock
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Stmt\Jump) {
                return $child->target;
            }
        }

        return null;
    }

    /** Foreach loop heads use Iterator_Valid — not ?: merge blocks (#5657). */
    private function isForeachIteratorHeaderCfgBlock(CfgBlock $cfg): bool
    {
        foreach ($cfg->children as $child) {
            if ($child instanceof Op\Iterator\Valid) {
                return true;
            }
        }

        return false;
    }

    /** Both ?: arms jump to the same CFG merge block (echo/assign phi, #3790, #5510). */
    private function jumpIfTargetsTernaryMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (\count($ifMerge->parents) < 2) {
            return false;
        }

        return $this->mergeCfgBlockUsesTernaryPhi($ifMerge);
    }

    /**
     * `||` short-circuit: php-cfg puts literal `true` on JumpIf->if and (bool) cast on ->else.
     * Lower else before if so the cast arm records the phi slot for the literal arm (#12745).
     */
    private function jumpIfTargetsLogicalOrShortCircuitLiteralIf(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (!$this->mergeCfgBlockUsesLogicalShortCircuit($ifMerge)) {
            return false;
        }
        $ifTail = $this->branchTailExprBeforeJump($stmt->if);
        $elseTail = $this->branchTailExprBeforeJump($stmt->else);

        return $ifTail instanceof Op\Expr\Assign
            && $ifTail->expr instanceof Operand\Literal
            && $elseTail instanceof Op\Expr\Cast\Bool_;
    }

    /**
     * `&&` short-circuit: php-cfg puts (bool) cast on JumpIf->if and literal `false` on ->else.
     * Lower if before else so the cast arm records the phi slot (#24506) — the opposite of `||`.
     */
    private function jumpIfTargetsLogicalAndShortCircuitCastIf(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (!$this->mergeCfgBlockUsesLogicalShortCircuit($ifMerge)) {
            return false;
        }
        $ifTail = $this->branchTailExprBeforeJump($stmt->if);
        $elseTail = $this->branchTailExprBeforeJump($stmt->else);

        return $ifTail instanceof Op\Expr\Cast\Bool_
            && $elseTail instanceof Op\Expr\Assign
            && $elseTail->expr instanceof Operand\Literal;
    }

    /** Both ?: arms jump to a merge block ending in RETURN (#4280, #8563). */
    private function jumpIfTargetsReturnMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        foreach ($ifMerge->children as $child) {
            if ($child instanceof Op\Terminal\Return_) {
                return true;
            }
        }

        return false;
    }
}
