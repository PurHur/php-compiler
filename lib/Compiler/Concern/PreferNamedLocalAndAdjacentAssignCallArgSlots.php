<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Variable as CfgVariable;

/**
 * Prefer named-local call-arg slots + adjacent assign emit (#36387 / #36403).
 *
 * Extracted from {@see FinalizeArrayFamilyCallArgSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c call-arg /
 * CV bind edges and Zend/zend_execute.c ASSIGN when php-cfg wires a later named
 * local read to a preceding call's dead result temp (#9074, #9487, #9973,
 * #16281, #18442, #28038). Move-only; no behavior change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FinalizeArrayFamilyCallArgSlots).
 */
trait PreferNamedLocalAndAdjacentAssignCallArgSlots
{
    /**
     * Named locals after ?: echo must not be remapped to merge-phi producer temps (#9487).
     */
    private function namedLocalCallArgSlotIfBound(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): ?string {
        $probe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $probe = $cfgCallOp->args[(int) $argIndex];
        }
        $name = Block::resolveVariableName($probe);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($probe);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($probe, $block);
            if (null !== $assignedNamed) {
                return (string) $assignedNamed;
            }
            return null;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot || !$block->isNamedVariableSlot((int) $namedSlot)) {
            return null;
        }

        return (string) $namedSlot;
    }

    /**
     * php-cfg may wire a later named local read to a preceding call's dead result temp (#9074).
     */
    private function preferNamedLocalCallArgSlot(
        Operand $arg,
        Block $block,
        ?string $valueSlot,
        ?string $calleeName = null
    ): ?string
    {
        if (null === $valueSlot) {
            return null;
        }
        $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($arg, $block);
        if (null !== $assignedNamed) {
            return (string) $assignedNamed;
        }
        if (
            $this->callArgOperandIsClosureValue($arg, $block)
            && !$this->isNamedVariableOperand($arg)
            && null === $this->namedLocalCallArgSlotIfBound($arg, $block)
        ) {
            return $valueSlot;
        }
        $name = Block::resolveVariableName($arg);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($arg);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            return $valueSlot;
        }
        if (null !== $calleeName && $name === $calleeName) {
            return $valueSlot;
        }
        // php-cfg dead temps for hoisted scalar ConstFetch / Cast preludes (#9140, #10143).
        if (\in_array(strtolower($name), ['true', 'false', 'null', 'nan', 'inf'], true)) {
            return $valueSlot;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot) {
            return $valueSlot;
        }
        if (!$block->isNamedVariableSlot((int) $namedSlot)) {
            return $valueSlot;
        }
        if ((int) $namedSlot === (int) $valueSlot) {
            return $valueSlot;
        }
        // Inline producer temp must not replace an unbound named local (#9973, #9924).
        // Function-local statics bind via TYPE_DECLARE_FUNCTION_STATIC, not ASSIGN (#28038).
        if (
            !$this->blockHasAssignToSlot($block, (int) $namedSlot)
            && !$this->blockHasAssignToSlotInParentBlocks($block, (int) $namedSlot)
            && !$this->blockHasFunctionStaticDeclareToSlot($block, (int) $namedSlot)
        ) {
            return $valueSlot;
        }

        return $namedSlot;
    }

    /**
     * `$path = __DIR__ . '/x'; f($path)` — bind the named local when Concat is inlined (#9973).
     *
     * @return list<OpCode>
     */
    private function tryEmitAdjacentAssignForInlineCallArg(
        Operand $arg,
        ?string $valueSlot,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): array {
        if (null === $valueSlot || null === $cfgCallOp || null === $block->orig) {
            return [];
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return [];
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg || !$this->operandsReferToSameVariable($arg, $callArg)) {
            return [];
        }
        $children = $block->orig->children;
        $prev = null;
        foreach ($children as $i => $child) {
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'args')
                || !is_array($child->args)
            ) {
                continue;
            }
            if ($child !== $cfgCallOp) {
                $sameCall = false;
                if (
                    property_exists($cfgCallOp, 'name')
                    && property_exists($child, 'name')
                    && $this->operandsReferToSameVariable($child->name, $cfgCallOp->name)
                ) {
                    $sameCall = true;
                }
                if (!$sameCall) {
                    continue;
                }
            }
            $siteArg = $child->args[$argIndex] ?? null;
            if (null === $siteArg || !$this->operandsReferToSameVariable($siteArg, $callArg)) {
                continue;
            }
            $prev = $children[$i - 1] ?? null;
            break;
        }
        if (!$prev instanceof Op\Expr\Assign || !$this->operandsReferToSameVariable($prev->var, $callArg)) {
            return [];
        }
        $destSlot = $block->getVarSlot($prev->var, false);
        // List destruct assigns compile in the parent block; skip merge-block phi bind (#10807).
        if (!$this->blockHasAssignToSlot($block, (int) $destSlot)) {
            return [];
        }

        $rhsSlot = (int) $valueSlot;
        if ($rhsSlot === (int) $destSlot) {
            // `$path = 'a' . 'b'` — CONCAT already wrote into destSlot; self-sync would clobber (#16281).
            if ($this->assignAdjacentToBinaryExprProducer($block, $prev)) {
                return [];
            }
            $exprSlot = $block->slotForOperand($prev->expr);
            if (null !== $exprSlot && (int) $exprSlot !== (int) $destSlot) {
                $rhsSlot = (int) $exprSlot;
            } else {
                // Reassigned locals (e.g. $f = fopen after fclose($f)) — use latest ASSIGN RHS (#16271).
                foreach ($block->opCodes as $op) {
                    if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === (int) $destSlot) {
                        $rhsSlot = (int) $op->arg3;
                    }
                }
            }
            if ($rhsSlot === (int) $destSlot) {
                return [];
            }
        }

        // `$a = ['k'=>1]; array_values($a)` — an identical dest←rhs ASSIGN already exists.
        // Emitting a second free()+store delrefs the HT and empties string-key walks under
        // thin AOT (#27545 / re-#27212). Peer: skip when CONCAT already wrote dest (#16281).
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_ASSIGN === $op->type
                && (int) $op->arg2 === (int) $destSlot
                && (int) $op->arg3 === (int) $rhsSlot
            ) {
                return [];
            }
        }

        return [new OpCode(
            OpCode::TYPE_ASSIGN,
            $this->compileOperand($prev->result, $block, false),
            $destSlot,
            $rhsSlot
        )];
    }

    /** `$x = 'a' . 'b'; f($x)` — CFG places BinaryOp immediately before Assign (#16281). */
    private function assignAdjacentToBinaryExprProducer(Block $block, Op\Expr\Assign $assign): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $assignIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $assign) {
                $assignIndex = $i;
                break;
            }
        }
        if (null === $assignIndex || $assignIndex < 1) {
            return false;
        }

        $prev = $block->orig->children[$assignIndex - 1];

        return $prev instanceof Op\Expr\BinaryOp || $prev instanceof Op\Expr\ConcatList;
    }

    /**
     * `$ini = "flag = $v"; parse_ini_string($ini)` inside loops — dest must not alias ConcatList temp (#18442).
     *
     * @param-out int $destSlot
     * @param-out int $rhsSlot
     */
    private function reconcileEncapsedConcatListAssignSlots(
        Op\Expr\Assign $assign,
        Block $block,
        int &$destSlot,
        int &$rhsSlot
    ): void {
        $concat = $this->concatListProducerFromAssignExpr($assign->expr);
        if (null === $concat || null === $concat->result) {
            return;
        }
        $producerSlot = $block->slotForOperand($concat->result);
        if (null === $producerSlot) {
            return;
        }
        $producerSlot = (int) $producerSlot;
        $rhsSlot = $producerSlot;
        if ((int) $destSlot === $producerSlot) {
            $name = Block::resolveVariableName($assign->var);
            $cvSlot = null !== $name ? $block->slotIndexForVariableName($name) : null;
            if (null === $cvSlot || (int) $cvSlot === $producerSlot) {
                $cvSlot = $block->forceFreshVarSlot($assign->var);
            }
            $destSlot = (int) $cvSlot;
        }
    }

    /** @return ?Op\Expr\ConcatList */
    private function concatListProducerFromAssignExpr(Operand $expr): ?Op\Expr\ConcatList
    {
        $unwrap = $expr;
        while ($unwrap instanceof Operand\Temporary && null !== $unwrap->original) {
            $unwrap = $unwrap->original;
        }
        if ($unwrap instanceof Op\Expr\ConcatList) {
            return $unwrap;
        }

        return $this->unwrapConcatListExpr($expr);
    }

    private function blockHasAssignToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Function-local `static $x` binds the CV via DECLARE_FUNCTION_STATIC (#28038). */
    private function blockHasFunctionStaticDeclareToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_FUNCTION_STATIC === $op->type && (int) $op->arg1 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Parent CFG blocks (list destruct merge) may hold the assign lowering (#10807). */
    private function blockHasAssignToSlotInParentBlocks(Block $block, int $destSlot, array $visited = []): bool
    {
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $id = spl_object_id($parent);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            if ($this->blockHasAssignToSlot($parent, $destSlot)) {
                return true;
            }
            if ($this->blockHasAssignToSlotInParentBlocks($parent, $destSlot, $visited)) {
                return true;
            }
        }

        return false;
    }

}
