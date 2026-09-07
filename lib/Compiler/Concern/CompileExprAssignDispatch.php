<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Assign / AssignRef expression compile dispatch (#36387 / #36403).
 *
 * Extracted from {@see CompileExprDispatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Mirrors php-src Zend/zend_compile.c assign /
 * assign-by-ref compile (`zend_compile_assign`, `zend_compile_assign_ref`).
 * Move-only; no behavior change intended.
 */
trait CompileExprAssignDispatch
{
    /**
     * Lower Op\Expr\Assign (list-spread, static/instance property write, CV merge).
     *
     * @return list<OpCode>
     */
    protected function compileAssignExpr(Op\Expr\Assign $expr, Block $block): array
    {
        if (!$this->assignIsListSpread($expr)) {
            $this->rejectThisReassignment($expr->var);
            $this->rejectGlobalsWrite($expr->var, $expr, $block);
            $this->rejectNullsafeInWriteContext($expr->var, $block);
            $this->rejectNewExprInWriteContext($expr->var, $block, $expr->expr, $expr);
            $this->rejectArrayLiteralInWriteContext($expr->var, $block, $expr);
            $this->rejectGlobalConstInWriteContext($expr->var, $block, $expr);
            $this->rejectCallReturnInWriteContext($expr->var, $block, $expr);
        }
        if ($this->assignIsListSpread($expr)) {
            $this->rejectListSpreadAssignExpr($expr);
            $fromIndex = new Operand\Literal($expr->listSpreadFromIndex);
            $spreadOp = new OpCode(
                OpCode::TYPE_LIST_SPREAD_ASSIGN,
                $this->compileOperand($expr->var, $block, false),
                $this->compileOperand($expr->listSpreadRhs, $block, true),
                $this->compileOperand($fromIndex, $block, true),
            );
            $spreadOp->listSpreadExcludedKeys = $expr->listSpreadExcludedKeys ?? [];

            return [$spreadOp];
        }
        $staticPropertyFetch = $this->unwrapStaticPropertyFetch($expr->var);
        $emitStaticPropertyFetch = true;
        if (null === $staticPropertyFetch) {
            $staticPropertyFetch = $this->findStaticPropertyFetchForAssign($expr->var, $block);
            $emitStaticPropertyFetch = false;
        }
        if (null !== $staticPropertyFetch) {
            $fetchSlot = $this->compileOperand($staticPropertyFetch->result, $block, false);
            $rhsSlot = $this->compileOperand($expr->expr, $block, true);
            $ops = [];
            if ($emitStaticPropertyFetch) {
                $staticFetchOp = new OpCode(
                    OpCode::TYPE_STATIC_PROPERTY_FETCH,
                    $fetchSlot,
                    $this->compileClassNameOperand($staticPropertyFetch->class, $block),
                    $this->compileStaticPropertyNameSlot($staticPropertyFetch->name, $staticPropertyFetch->class, $block)
                );
                $this->assignSourceMetadata($staticFetchOp, $staticPropertyFetch);
                $ops[] = $staticFetchOp;
            }
            // One property write; publish used result without re-writing the slot (#29194).
            // Stamp ASSIGN (not only the fetch) so JIT private(set) Errors cite the write (#29665).
            $writeOp = new OpCode(
                OpCode::TYPE_ASSIGN,
                $fetchSlot,
                $fetchSlot,
                $rhsSlot
            );
            $this->assignSourceMetadata($writeOp, $expr);
            $ops[] = $writeOp;
            if ([] !== $expr->result->usages) {
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                $ops[] = new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $rhsSlot
                );
            }

            return $ops;
        }
        $propertyFetch = $this->unwrapPropertyFetch($expr->var)
            ?? $this->findCoalescePropertyFetch($expr->var, $block);
        if (null !== $propertyFetch) {
            $fetchSlot = $this->compileOperand($propertyFetch->result, $block, false);
            $rhsSlot = $this->compileOperand($expr->expr, $block, true);
            $fetchOp = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $fetchSlot,
                $this->compileOperand($propertyFetch->var, $block, true),
                $this->compileOperand($propertyFetch->name, $block, true)
            );
            // Assign-lowered property writes skip compileExpr(PropertyFetch); stamp line here (#21953).
            $this->assignSourceMetadata($fetchOp, $propertyFetch);
            // Write the property once. A follow-up ASSIGN must not use fetchSlot as dest —
            // that re-invokes __set for `$r = ($obj->prop = $v)` (#29194). Publish the
            // expression value into resultSlot only (dest=resultSlot).
            // Stamp the ASSIGN with the Assign expr so mid-method private(set) Errors
            // report the write line, not a stale callSiteLine (#29665 / zend_object_handlers.c).
            $writeOp = new OpCode(
                OpCode::TYPE_ASSIGN,
                $fetchSlot,
                $fetchSlot,
                $rhsSlot
            );
            $this->assignSourceMetadata($writeOp, $expr);
            $ops = [
                $fetchOp,
                $writeOp,
            ];
            if ([] !== $expr->result->usages) {
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                $ops[] = new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $rhsSlot
                );
            }

            return $ops;
        }

        $mergeAssignSlot = $this->branchMergeAssignSlot($block, $expr);
        if ($expr->expr instanceof Operand\Literal && null !== $block->orig) {
            $literalMerge = $this->branchJumpMergeTarget($block->orig);
            if (
                null !== $literalMerge
                && $this->mergeCfgBlockUsesLogicalShortCircuit($literalMerge)
            ) {
                $tail = $this->branchTailExprBeforeJump($block->orig);
                if ($tail === $expr) {
                    $literalPhi = $this->logicalShortCircuitSiblingPhiSlot($block)
                        ?? $this->logicalShortCircuitPhiMergeSlot($block);
                    if (null !== $literalPhi) {
                        $mergeAssignSlot = $literalPhi;
                    }
                }
            }
        }
        // Named CV assigns keep their own slots across try/catch merge (#29482).
        // Catch lowers before try (#6411); without this guard, catch's CV is recorded
        // as ternary echo-phi and the try-body assign is forced onto that sibling
        // slot — same class as #26490 (branchMergeAssignSlot) but this override path
        // previously bypassed the named-CV check.
        $assignIsNamedCv = null !== Block::resolveVariableName($expr->var);
        if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
            $mergeCfg = $this->branchJumpMergeTarget($block->orig);
            if (
                null !== $mergeCfg
                && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)
                && !$assignIsNamedCv
            ) {
                $recordedPhi = $this->ternaryMergePhiRhsSlot($mergeCfg);
                if (null !== $recordedPhi) {
                    $mergeAssignSlot = $recordedPhi;
                }
            }
        }
        if (null !== $mergeAssignSlot) {
            $root = Block::cfgVarRoot($expr->var);
            if ($root instanceof Operand\Variable) {
                $block->prebindCfgVarRoot($root, $mergeAssignSlot);
            } else {
                $block->bindScopeSlot($expr->var, $mergeAssignSlot);
            }
        }
        $destSlot = null !== $mergeAssignSlot
            ? $mergeAssignSlot
            : $this->compileOperand($expr->var, $block, false);
        if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
            $mergeCfg = $this->branchJumpMergeTarget($block->orig);
            if (
                null !== $mergeCfg
                && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)
                && !$assignIsNamedCv
            ) {
                if (!$this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
                    $this->ternaryMergePhiRhsSlots[$mergeCfg] = (int) $destSlot;
                }
            }
        }
        $rhsSlot = $this->compileOperand($expr->expr, $block, true);
        $this->reconcileEncapsedConcatListAssignSlots($expr, $block, $destSlot, $rhsSlot);
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $varRoot = Block::cfgVarRoot($expr->var);
        if (null !== $varRoot) {
            // Register the CV lvalue slot — assign.result temps diverge after $a[] writes (#12712).
            $block->registerNamedAssignDest($varRoot, (int) $destSlot);
        }

        $assignOp = new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $destSlot,
            $rhsSlot
        );
        $block->registerAssignResultLvalue((int) $resultSlot, (int) $destSlot);
        $this->assignSourceMetadata($assignOp, $expr);

        return [$assignOp];
    }

    /**
     * Lower Op\Expr\AssignRef (ref-binding rejects + ASSIGN_REF / result publish).
     *
     * @return list<OpCode>
     */
    protected function compileAssignRefExpr(Op\Expr\AssignRef $expr, Block $block): array
    {
        $this->rejectThisReassignment($expr->var);
        $this->rejectGlobalsWrite($expr->var, $expr, $block);
        $this->rejectNullsafeInWriteContext($expr->var, $block);
        $this->rejectNewExprInWriteContext($expr->var, $block, null, null, $expr);
        $this->rejectArrayLiteralInWriteContext($expr->var, $block, $expr);
        $this->rejectGlobalConstInWriteContext($expr->var, $block, $expr);
        $this->rejectCallReturnInWriteContext($expr->var, $block, $expr);
        // Zend zend_compile.c: cannot acquire a reference to $GLOBALS (#15627).
        $this->rejectGlobalsReferenceAcquisition($expr->expr);
        // Zend zend_compile.c: &$a?->x / &$a?->m() (#26638).
        $this->rejectNullsafeReferenceAcquisition($expr->expr, $block);
        // Zend zend_compile.c: ref-binding to const/class-const array element (#5409).
        $this->rejectGlobalConstInWriteContext($expr->expr, $block, $expr);
        $bindRefFlags = 0;
        $dimFetch = $this->unwrapArrayDimFetch($expr->expr)
            ?? $this->findArrayDimFetchForResult($expr->expr, $block);
        $arrayLiteral = null !== $dimFetch
            ? ($this->unwrapArrayLiteralExpr($dimFetch->var)
                ?? $this->findArrayExprForResult($dimFetch->var, $block))
            : null;
        if (null !== $arrayLiteral) {
            // Zend zend_compile_list_assign: ref target from inline array literal (#3799).
            $bindRefFlags = 1;
        } elseif (0 !== $this->assignRefBindRefFlags) {
            $bindRefFlags = $this->assignRefBindRefFlags;
        }
        $ops = [new OpCode(
            OpCode::TYPE_ASSIGN_REF,
            $this->compileOperand($expr->var, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $bindRefFlags ?: null
        )];
        if ([] !== $expr->result->usages) {
            $ops[] = new OpCode(
                OpCode::TYPE_ASSIGN,
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->var, $block, false),
                $this->compileOperand($expr->expr, $block, true)
            );
        }
        return $ops;
    }
}
