<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPTypes\Type;

/**
 * Ternary (`?:`) merge-phi detect and || / && short-circuit slot helpers (#36387 / #36403).
 *
 * Extracted from {@see TernaryAndLogicalShortCircuit} so gen-0 split-TU can
 * hollow a smaller Concern TU. Covers mergeCfgBlockUsesEchoPhi through
 * resolveExitLogicalShortCircuitCallArgSlot. Companion to
 * {@see TernaryMergeVarSlotCompile}. Mirrors php-src Zend/zend_compile.c
 * ternary / JMPZ_EX / JMPNZ_EX short-circuit lowering — move-only; no
 * behavior change intended.
 *
 * Visibility stays private where call sites require it.
 */
trait TernaryMergeAndLogicalShortCircuitSlots
{
    /** `?:` in `echo`/concat merge uses echo phi slots; `return ?:` uses RETURN (#4280); `throw ?:` uses TYPE_THROW (#7037). */
    private function mergeCfgBlockUsesEchoPhi(CfgBlock $merge): bool
    {
        foreach ($merge->children as $child) {
            if ($child instanceof Op\Terminal\Echo_) {
                return true;
            }
            if ($child instanceof Op\Terminal\Throw_) {
                return true;
            }
        }

        return false;
    }

    /** `$s = $cond ? $a : $b` merge assigns a shared phi temporary into a named local (#9159). */
    private function mergeCfgBlockUsesAssignPhi(CfgBlock $merge): bool
    {
        if (\count($merge->parents) < 2) {
            return false;
        }
        foreach ($merge->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            $destRoot = Block::cfgVarRoot($child->var);
            if (!$destRoot instanceof Operand\Variable) {
                continue;
            }
            $phiOperand = $child->expr;
            if (!$phiOperand instanceof Operand) {
                continue;
            }
            $matchedParents = 0;
            foreach ($merge->parents as $parent) {
                $armVar = $this->mergeBranchAssignVarOperand($parent);
                if (null === $armVar) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($armVar, $phiOperand)) {
                    ++$matchedParents;
                }
            }
            if ($matchedParents >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * ?: arms assign to a shared merge temporary (#15816, Zend/zend_compile.c).
     */
    private function mergeCfgBlockUsesTernaryBranchLiteralAssign(CfgBlock $merge): bool
    {
        if (\count($merge->parents) < 2) {
            return false;
        }
        $armVar = null;
        foreach ($merge->parents as $parent) {
            $tail = $this->branchTailExprBeforeJump($parent);
            if (!$tail instanceof Op\Expr\Assign) {
                return false;
            }
            if (null === $armVar) {
                $armVar = $tail->var;
            } elseif (!$this->operandsReferToSameVariable($tail->var, $armVar)) {
                return false;
            }
        }

        return null !== $armVar;
    }

    private function mergeCfgBlockUsesTernaryPhi(CfgBlock $merge): bool
    {
        return $this->mergeCfgBlockUsesEchoPhi($merge)
            || $this->mergeCfgBlockUsesAssignPhi($merge)
            || $this->mergeCfgBlockUsesTernaryBranchLiteralAssign($merge)
            || $this->mergeCfgBlockUsesLogicalShortCircuit($merge);
    }

    /** && / || merge: one arm ends in (bool) cast, sibling in literal assign (php-cfg parseShortCircuiting). */
    private function mergeCfgBlockUsesLogicalShortCircuit(CfgBlock $merge): bool
    {
        if (\count($merge->parents) < 2) {
            return false;
        }
        $hasBoolCastArm = false;
        $hasLiteralAssignArm = false;
        foreach ($merge->parents as $parent) {
            $tail = $this->branchTailExprBeforeJump($parent);
            if ($tail instanceof Op\Expr\Cast\Bool_) {
                $hasBoolCastArm = true;
            }
            if ($tail instanceof Op\Expr\Assign && $tail->expr instanceof Operand\Literal) {
                $hasLiteralAssignArm = true;
            }
        }

        return $hasBoolCastArm && $hasLiteralAssignArm;
    }

    private function branchTailExprBeforeJump(CfgBlock $branch): ?Op\Expr
    {
        $children = $branch->children;
        for ($i = \count($children) - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Stmt\Jump) {
                continue;
            }
            if ($child instanceof Op\Expr) {
                return $child;
            }

            break;
        }

        return null;
    }

    /** && / || merge-arm tail before JUMP — literal assign dest or long-arm (bool) cast result (#10626, #12745). */
    private function logicalShortCircuitTailPhiSlot(Block $branchBlock): ?int
    {
        for ($i = $branchBlock->nOpCodes - 1; $i >= 0; --$i) {
            $op = $branchBlock->opCodes[$i];
            if (OpCode::TYPE_JUMP === $op->type) {
                continue;
            }
            if (OpCode::TYPE_ASSIGN === $op->type) {
                return (int) $op->arg2;
            }
            if (OpCode::TYPE_CAST_BOOL === $op->type) {
                return (int) $op->arg1;
            }

            break;
        }

        return null;
    }

    /** && / || short-arm literal assign — phi slot from already-compiled sibling tail (#12745). */
    private function logicalShortCircuitSiblingPhiSlot(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        $mergeCfg = $this->branchJumpMergeTarget($branch->orig);
        if (null === $mergeCfg) {
            return null;
        }
        foreach ($mergeCfg->parents as $parentCfg) {
            if ($parentCfg === $branch->orig || !$this->seen->contains($parentCfg)) {
                continue;
            }
            $phi = $this->logicalShortCircuitTailPhiSlot($this->seen[$parentCfg]);
            if (null !== $phi) {
                return $phi;
            }
        }

        return null;
    }

    /**
     * Phi slot for a ||/&& merge reached by JUMP from this block (#25850).
     *
     * Used when a (bool) cast lives inside an inner short-circuit merge (e.g. &&) but feeds an
     * outer || merge — {@see logicalShortCircuitPhiMergeSlot} would otherwise return the inner phi.
     */
    private function logicalShortCircuitJumpTargetPhiMergeSlot(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if (!$this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
                continue;
            }
            $recorded = $this->ternaryMergePhiRhsSlot($mergeCfg);
            if (null !== $recorded) {
                return $recorded;
            }
            foreach ($mergeCfg->parents as $parentCfg) {
                if ($parentCfg === $branch->orig || !$this->seen->contains($parentCfg)) {
                    continue;
                }
                $phi = $this->logicalShortCircuitTailPhiSlot($this->seen[$parentCfg]);
                if (null !== $phi) {
                    return $phi;
                }
            }
        }

        return null;
    }

    /** && / || long-arm bool cast must store into the recorded phi merge slot (#10626). */
    private function logicalShortCircuitPhiMergeSlot(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        if (
            $this->mergeCfgBlockUsesLogicalShortCircuit($branch->orig)
            && \count($branch->orig->parents) >= 2
        ) {
            $recorded = $this->ternaryMergePhiRhsSlot($branch->orig);
            if (null !== $recorded) {
                return $recorded;
            }
            foreach ($branch->orig->parents as $parentCfg) {
                if (!$this->seen->contains($parentCfg)) {
                    continue;
                }
                $phi = $this->logicalShortCircuitTailPhiSlot($this->seen[$parentCfg]);
                if (null !== $phi) {
                    return $phi;
                }
            }
        }
        $jumpTargetPhi = $this->logicalShortCircuitJumpTargetPhiMergeSlot($branch);
        if (null !== $jumpTargetPhi) {
            return $jumpTargetPhi;
        }

        return null;
    }

    /**
     * Reserve one phi slot for `||` / `&&` short-circuit merge before both arms are lowered (#12745).
     *
     * @return int|null slot index stored in {@see $ternaryMergePhiRhsSlots}
     */
    private function seedLogicalShortCircuitPhiSlot(CfgBlock $branchCfg, Block $branch, Operand $phiOperand): ?int
    {
        $mergeCfg = $this->branchJumpMergeTarget($branchCfg);
        if (null === $mergeCfg || !$this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
            return null;
        }
        if ($this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
            return $this->ternaryMergePhiRhsSlots[$mergeCfg];
        }
        $slot = $branch->forceFreshVarSlot($phiOperand);
        $this->ternaryMergePhiRhsSlots[$mergeCfg] = $slot;
        $root = Block::cfgVarRoot($phiOperand);
        if (null !== $root) {
            $rootName = Block::resolveVariableName($root);
            if (null === $rootName || '' === $rootName) {
                if (!$this->ternaryMergeVarSlots->contains($mergeCfg)) {
                    $this->ternaryMergeVarSlots[$mergeCfg] = new SplObjectStorage();
                }
                /** @var SplObjectStorage<CfgVariable, int> $map */
                $map = $this->ternaryMergeVarSlots[$mergeCfg];
                $map[$root] = $slot;
            }
        }

        return $slot;
    }

    /** `||`-only phi slot for dead call-arg temps (do not disturb `&&` merge wiring). */
    private function logicalShortCircuitOrPhiMergeSlot(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if (!$this->mergeCfgUsesLogicalOrShortCircuit($mergeCfg)) {
                continue;
            }
            $recorded = $this->ternaryMergePhiRhsSlot($mergeCfg);
            if (null !== $recorded) {
                return $recorded;
            }
        }

        return null;
    }

    private function mergeCfgUsesLogicalOrShortCircuit(CfgBlock $mergeCfg): bool
    {
        if (!$this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
            return false;
        }
        foreach ($mergeCfg->parents as $parentCfg) {
            $tail = $this->branchTailExprBeforeJump($parentCfg);
            if (
                $tail instanceof Op\Expr\Assign
                && $tail->expr instanceof Operand\Literal
                && true === $tail->expr->value
            ) {
                return true;
            }
        }

        return false;
    }

    /** exit($a && $b) ? … — dead call-arg temp must use && phi / parent cast slot (#11592). */
    private function resolveExitLogicalShortCircuitCallArgSlot(Block $block): ?string
    {
        $phi = $this->logicalShortCircuitPhiMergeSlot($block);
        if (null !== $phi) {
            return (string) $phi;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->parents as $parentCfg) {
            if (!$this->seen->contains($parentCfg)) {
                continue;
            }
            $parentBlock = $this->seen[$parentCfg];
            for ($i = $parentBlock->nOpCodes - 1; $i >= 0; --$i) {
                $op = $parentBlock->opCodes[$i];
                if (OpCode::TYPE_JUMP === $op->type) {
                    continue;
                }
                if (OpCode::TYPE_CAST_BOOL === $op->type) {
                    return (string) $op->arg1;
                }
                if (OpCode::TYPE_ASSIGN === $op->type) {
                    return (string) $op->arg2;
                }
                break;
            }
        }

        return null;
    }

}
