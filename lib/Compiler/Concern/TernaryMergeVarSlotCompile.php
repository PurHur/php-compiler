<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Ternary (?:) merge phi / var-slot wiring (#36387 / #36403).
 *
 * Extracted from {@see TernaryAndLogicalShortCircuit} so gen-0 split-TU can
 * hollow a smaller Concern TU. Companion to {@see TernaryMergeAndLogicalShortCircuitSlots}. Covers recordTernaryMergeVarSlots through
 * mergeEchoSlotForBranch. Mirrors php-src Zend/zend_compile.c ternary / QM_ASSIGN
 * phi wiring — move-only; no behavior change intended.
 *
 * Visibility stays private where LintCompiler / call sites require it.
 */
trait TernaryMergeVarSlotCompile
{
    private function recordTernaryMergeVarSlots(CfgBlock $branchCfg, Block $compiled): void
    {
        $jumpMerge = $this->branchJumpMergeTarget($branchCfg);
        if (null !== $jumpMerge && $this->mergeCfgBlockUsesLogicalShortCircuit($jumpMerge)) {
            $this->recordTernaryMergePhiRhsSlot($jumpMerge, $compiled);
        }
        foreach ($this->ternaryMergeTargets($branchCfg) as $mergeCfg) {
            if (!$this->ternaryMergeVarSlots->contains($mergeCfg)) {
                $this->ternaryMergeVarSlots[$mergeCfg] = new SplObjectStorage();
            }
            /** @var SplObjectStorage<CfgVariable, int> $map */
            $map = $this->ternaryMergeVarSlots[$mergeCfg];
            $phiRoot = $this->mergeBranchAssignVarRoot($branchCfg);
            $phiSlot = null;
            if (null !== $phiRoot) {
                foreach ($compiled->eachCfgVarRootSlot() as [$root, $slot]) {
                    if ($root === $phiRoot) {
                        $phiSlot = $slot;
                        break;
                    }
                }
            }
            if (null === $phiSlot && $this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
            }
            if (null !== $phiRoot && null !== $phiSlot) {
                $map[$phiRoot] = $phiSlot;

                continue;
            }
            $this->recordTernaryMergePhiRhsSlot($mergeCfg, $compiled);
            foreach ($compiled->eachCfgVarRootSlot() as [$root, $slot]) {
                if (!$map->contains($root)) {
                    // && / || long arms read named locals (e.g. str_contains($out, …)); do not
                    // remap those roots through the bool-cast phi merge map (#15183, #10626).
                    if ($this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
                        $rootName = Block::resolveVariableName($root);
                        if (null !== $rootName && '' !== $rootName) {
                            continue;
                        }
                    }
                    $map[$root] = $slot;
                }
            }
        }
    }

    private function mergeBranchAssignVarRoot(CfgBlock $branchCfg): ?Operand\Variable
    {
        $assignVar = $this->mergeBranchAssignVarOperand($branchCfg);
        if (null === $assignVar) {
            return null;
        }
        $root = Block::cfgVarRoot($assignVar);

        return $root instanceof Operand\Variable ? $root : null;
    }

    private function mergeBranchAssignVarOperand(CfgBlock $branchCfg): ?Operand
    {
        $children = $branchCfg->children;
        $jumpIdx = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Op\Stmt\Jump) {
                $jumpIdx = $i;
                break;
            }
        }
        if (null === $jumpIdx) {
            return null;
        }
        for ($i = $jumpIdx - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Assign) {
                return $child->var;
            }
            // && / || long arm: (bool) cast writes the phi; earlier assigns are RHS side effects (#24506).
            if ($child instanceof Op\Expr\Cast\Bool_) {
                return null;
            }
            if (!$child instanceof Op\Expr) {
                break;
            }
        }

        return null;
    }

    private function applyTernaryMergeVarSlots(CfgBlock $branchCfg, Block $compiled): void
    {
        foreach ($this->ternaryMergeTargets($branchCfg) as $mergeCfg) {
            if (!$this->ternaryMergeVarSlots->contains($mergeCfg)) {
                continue;
            }
            /** @var SplObjectStorage<CfgVariable, int> $map */
            $map = $this->ternaryMergeVarSlots[$mergeCfg];
            foreach ($map as $root) {
                if ($this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
                    $rootName = Block::resolveVariableName($root);
                    if (null !== $rootName && '' !== $rootName) {
                        continue;
                    }
                }
                $compiled->prebindCfgVarRoot($root, $map[$root]);
            }
        }
    }

    /** When merge block is already lowered, ?: branch assigns must use its ECHO slot (#3790). */
    private function branchMergeAssignSlot(Block $branch, Op\Expr\Assign $assign): ?int
    {
        if ($this->compilingSwitchJumpIfChain) {
            return null;
        }
        if (null === $branch->orig) {
            return null;
        }
        if ($this->isPropertyWriteAssign($assign, $branch)) {
            return null;
        }
        if ($this->isArrayDimWriteAssign($assign, $branch)) {
            return null;
        }
        // Named CV assigns keep their own slots — never rebind to ?: / try-merge phi temps.
        // #17158 covered re-assigns that already had a named-assign dest; first assigns inside
        // try/catch (e.g. `$rhs = 123` before `$o instanceof $rhs`) were still classified as
        // merge-branch phi seeds and stole an outer local's slot (#26490, re-#4339).
        if (null !== Block::resolveVariableName($assign->var)) {
            return null;
        }
        $mergeCfg = $this->branchJumpMergeTarget($branch->orig);
        if (null !== $mergeCfg && $this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
            $tail = $this->branchTailExprBeforeJump($branch->orig);
            if (
                $tail === $assign
                && $assign->expr instanceof Operand\Literal
            ) {
                $phi = $this->logicalShortCircuitPhiMergeSlot($branch);
                if (null !== $phi) {
                    return $phi;
                }
            }
        }
        if (!$this->isMergeBranchAssign($branch, $assign)) {
            return null;
        }
        $mergeCfg = $this->branchJumpMergeTarget($branch->orig);
        if (null !== $mergeCfg && $this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
            $seeded = $this->seedLogicalShortCircuitPhiSlot($branch->orig, $branch, $assign->var);
            if (null !== $seeded) {
                return $seeded;
            }
        }
        if (null !== $mergeCfg) {
            $recordedPhi = $this->ternaryMergePhiRhsSlot($mergeCfg);
            if (null !== $recordedPhi) {
                return $recordedPhi;
            }
        }
        if (null !== $mergeCfg && $this->isForeachIteratorHeaderCfgBlock($mergeCfg)) {
            return null;
        }
        if (null !== $mergeCfg && $this->seen->contains($mergeCfg)) {
            // Echo/return ?: phi temporaries must still target the merge ECHO/RETURN slot (#3787, #4280, #5506).
            if ($assign->var instanceof Temporary && null === $this->mergeReturnSlot($this->seen[$mergeCfg])) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
        }
        if (null === Block::cfgVarRoot($assign->var) && !$assign->var instanceof Temporary) {
            return null;
        }
        if (null !== $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
            $siblingSlot = $this->siblingMergeBranchAssignDestSlot($mergeCfg, $branch->orig);
            if (null !== $siblingSlot) {
                return $siblingSlot;
            }
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
            if ($this->ternaryMergeVarSlots->contains($mergeCfg)) {
                /** @var SplObjectStorage<CfgVariable, int> $map */
                $map = $this->ternaryMergeVarSlots[$mergeCfg];
                foreach ($map as $root) {
                    return $map[$root];
                }
            }
        }

        return null;
    }

    private function siblingMergeBranchAssignDestSlot(CfgBlock $mergeCfg, CfgBlock $currentBranch): ?int
    {
        foreach ($mergeCfg->parents as $parentCfg) {
            if ($parentCfg === $currentBranch || !$this->seen->contains($parentCfg)) {
                continue;
            }
            $phi = $this->logicalShortCircuitTailPhiSlot($this->seen[$parentCfg]);
            if (null !== $phi) {
                return $phi;
            }
        }

        return null;
    }

    private function isMergeBranchAssign(Block $branch, Op\Expr\Assign $assign): bool
    {
        if (null === $branch->orig) {
            return false;
        }
        $expectedVar = $this->mergeBranchAssignVarOperand($branch->orig);
        if (null === $expectedVar) {
            return false;
        }

        return $this->operandsReferToSameVariable($expectedVar, $assign->var);
    }

    private function mergeEchoSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_ECHO === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    /** `$dest = $phi` merge block carries the phi slot on the assign RHS (#9159). */
    private function mergeAssignPhiRhsSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || null === $op->arg3) {
                continue;
            }
            // Consecutive match() in one merge block seeds later results with literal `''`;
            // constant RHS is not a phi temp slot (#9856).
            if (isset($merge->constants[$op->arg3])) {
                continue;
            }

            return (int) $op->arg3;
        }

        return null;
    }

    private function recordTernaryMergePhiRhsSlot(CfgBlock $mergeCfg, Block $compiled): void
    {
        if ($this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
            return;
        }
        $phi = $this->logicalShortCircuitTailPhiSlot($compiled);
        if (null !== $phi) {
            $this->ternaryMergePhiRhsSlots[$mergeCfg] = $phi;
        }
    }

    private function ternaryMergePhiRhsSlot(CfgBlock $mergeCfg): ?int
    {
        if (!$this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
            return null;
        }

        return $this->ternaryMergePhiRhsSlots[$mergeCfg];
    }

    /** `return $a ? $b : $c` merge block carries the phi slot on RETURN (#4280). */
    private function mergeReturnSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_RETURN === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    /** `throw $a ? $b : $c` merge block carries the phi slot on TYPE_THROW (#7037). */
    private function mergeThrowSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_THROW === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    private function mergePhiResultSlot(Block $merge): ?int
    {
        return $this->mergeEchoSlot($merge)
            ?? $this->mergeAssignPhiRhsSlot($merge)
            ?? $this->mergeReturnSlot($merge)
            ?? $this->mergeThrowSlot($merge);
    }

    /** ?: branch throw `new` must not reuse merge phi / echo slot (#3802). */
    private function mergeEchoSlotForBranch(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $slot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $slot) {
                    return $slot;
                }
            }
            if ($this->ternaryMergeVarSlots->contains($mergeCfg)) {
                /** @var SplObjectStorage<CfgVariable, int> $map */
                $map = $this->ternaryMergeVarSlots[$mergeCfg];
                foreach ($map as $root) {
                    return $map[$root];
                }
            }
        }

        return null;
    }
}
