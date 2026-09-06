<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;

/**
 * Inline call-arg coalesce / nullsafe slot helpers (#36387 / prior #36147).
 *
 * Extracted from {@see FindInlineCallArgProducerSlot} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see compileCallArgCoalesceSlot},
 * {@see findCoalesceStmtForCallArg}, {@see slotForNullsafeResult}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c coalesce / nullsafe result operand wiring —
 * move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait FindInlineCoalesceAndNullsafeCallArgSlots
{
    /**
     * Stmt-level ?? must not supply slots for literal / hoisted scalar call args (#9225, #10380).
     */
    private function isCallArgUnrelatedToPriorStmtCoalesce(Operand $callArg): bool
    {
        if ($callArg instanceof Operand\Literal || $this->isEmbeddedCallLiteralArg($callArg)) {
            return true;
        }
        // php-cfg clones stmt-level ?? call-arg temps from inner ConstFetch/null operands (#11801).
        $cfgClone = $callArg instanceof Operand\Temporary;
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null !== $name) {
                $lookup = strtolower($name);
                if (\in_array($lookup, ['true', 'false'], true)) {
                    return true;
                }
                if ('null' === $lookup) {
                    return !$cfgClone;
                }
                if (!$cfgClone && \PHPCompiler\ext\standard\StdlibConstants::hasCoreIntByName($lookup)) {
                    return true;
                }
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($callArg);
        if (null !== $vm && \in_array($vm->type, [
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_NULL,
        ], true)) {
            return !$cfgClone;
        }

        return false;
    }

    /** header() replace/response_code must not reuse stmt-level ?? slots (#1887, 005-SessionsWeb). */
    private function headerScalarCallArgMustUseDirectOperand(?string $calleeName, int $argIndex): bool
    {
        if (null === $calleeName || $argIndex < 1) {
            return false;
        }

        return 'header' === strtolower(ltrim($calleeName, '\\'));
    }

    /**
     * True when $cfg's children include any stmt-level BinaryOp\Coalesce (#36387).
     */
    private function cfgBlockHasCoalesceStmt(CfgBlock $cfg): bool
    {
        $id = spl_object_id($cfg);
        if (\array_key_exists($id, $this->cfgBlockHasCoalesceStmtCache)) {
            return $this->cfgBlockHasCoalesceStmtCache[$id];
        }
        $has = false;
        foreach ($cfg->children as $child) {
            if ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                $has = true;
                break;
            }
        }
        $this->cfgBlockHasCoalesceStmtCache[$id] = $has;

        return $has;
    }

    /**
     * @return ?Op\Expr\BinaryOp\Coalesce
     */
    private function findCoalesceStmtForCallArg(Operand $arg, Block $block): ?Op\Expr\BinaryOp\Coalesce
    {
        foreach ($this->findEmbeddedCoalesces($arg) as $coalesce) {
            return $coalesce;
        }
        if (null === $block->orig) {
            return null;
        }
        // Nested call stmt blocks with no ?? still paid O(children × args) here via the
        // FuncCall rescan below — early-out when the CFG has zero Coalesce stmts (#36387).
        if (!$this->cfgBlockHasCoalesceStmt($block->orig)) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\BinaryOp\Coalesce
                && ($child->result === $arg || $this->operandsReferToSameVariable($child->result, $arg))
            ) {
                if (!$this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
                    return $child;
                }
            }
        }
        // php-cfg clones call-arg temps from stmt Coalesce result (#8766, #8902).
        foreach ($block->orig->children as $i => $child) {
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'args')
                || !is_array($child->args)
            ) {
                continue;
            }
            $argMatches = false;
            $matchedCallArg = null;
            foreach ($child->args as $callArg) {
                if ($callArg === $arg || $this->operandsReferToSameVariable($callArg, $arg)) {
                    $argMatches = true;
                    $matchedCallArg = $callArg;
                    $root = $this->unwrapOperandChain($callArg);
                    if ($root instanceof Op\Expr\BinaryOp\Coalesce) {
                        return $root;
                    }
                    break;
                }
            }
            if (!$argMatches) {
                continue;
            }
            // Literal / unrelated call args must not pick up a prior stmt-level ?? (#9225, 009-FastCGIWeb).
            if (null !== $matchedCallArg && $this->isCallArgUnrelatedToPriorStmtCoalesce($matchedCallArg)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                $prev = $block->orig->children[$j];
                if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                    // php-cfg clones call-arg temps from stmt Coalesce.result (#8766, #8902, #9479).
                    if ($j === $i - 1) {
                        $ordinalCoalesce = $this->findStmtCoalesceForOrdinalCallArg(
                            $child,
                            $matchedCallArg,
                            $block
                        );
                        if (null !== $ordinalCoalesce) {
                            return $ordinalCoalesce;
                        }

                        return $prev;
                    }
                    if (
                        null !== $matchedCallArg
                        && (
                            $prev->result === $matchedCallArg
                            || $this->operandsReferToSameVariable($prev->result, $matchedCallArg)
                            || $this->operandsReferToSameVariable($prev->left, $matchedCallArg)
                        )
                    ) {
                        return $prev;
                    }
                    // php-cfg may lower later call-arg producers (e.g. var_export(..., true)) between ?? and FuncCall (#11601).
                    if ($this->onlyInlineCallArgProducersBetweenIndices($block->orig->children, $j, $i)) {
                        // Stmt-level ?? feeds arg #0 only; trailing hoisted true/false/null are unrelated (#13789).
                        $firstArg = $child->args[0] ?? null;
                        $feedsFirstArg = null === $matchedCallArg
                            || (
                                null !== $firstArg
                                && (
                                    $firstArg === $matchedCallArg
                                    || $this->operandsReferToSameVariable($firstArg, $matchedCallArg)
                                    || $this->operandsReferToSameVariable($prev->result, $matchedCallArg)
                                )
                            );
                        $feedsHaystackArg = false;
                        if (!$feedsFirstArg && null !== $matchedCallArg) {
                            $calleeLc = strtolower($this->resolveCfgFuncCallName($child) ?? '');
                            if (\in_array($calleeLc, ['in_array', 'array_search', 'array_key_exists'], true)) {
                                $haystackArg = $child->args[1] ?? null;
                                $feedsHaystackArg = null !== $haystackArg
                                    && $this->callArgOperandExpectsArrayProducer($haystackArg)
                                    && (
                                        $haystackArg === $matchedCallArg
                                        || $this->operandsReferToSameVariable($haystackArg, $matchedCallArg)
                                    );
                            }
                        }
                        if ($feedsFirstArg || $feedsHaystackArg) {
                            return $prev;
                        }
                    }
                    break;
                }
                if (
                    $prev instanceof Op\Expr\Assign
                    && $j > 0
                ) {
                    $maybeCoalesce = $block->orig->children[$j - 1];
                    if (
                        $maybeCoalesce instanceof Op\Expr\BinaryOp\Coalesce
                        && $this->isCoalesceAssignTail($prev, $maybeCoalesce)
                    ) {
                        // ??= expression value before call — php-cfg inserts Assign between Coalesce and FuncCall (#5337, #10898).
                        return $maybeCoalesce;
                    }
                }
                if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                    break;
                }
                if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Nullsafe lowering splits CFG blocks; result slot lives on TYPE_NULLSAFE (#9732, #9171).
     *
     * @param Op\Expr\NullsafePropertyFetch|Op\Expr\NullsafeMethodCall $nullsafe
     */
    private function slotForNullsafeResult(Block $block, Op\Expr $nullsafe): ?int
    {
        $nullsafeId = spl_object_id($nullsafe);
        if (isset($this->nullsafeResultSlots[$nullsafeId])) {
            return $this->nullsafeResultSlots[$nullsafeId];
        }
        $slot = $block->slotForOperand($nullsafe->result);
        if (null !== $slot) {
            return $slot;
        }
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_NULLSAFE === $op->type) {
                    return $op->arg1;
                }
            }
            foreach ($current->parents as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    private function slotForCoalesceResult(Block $block, Op\Expr\BinaryOp\Coalesce $coalesce): ?int
    {
        $coalesceId = spl_object_id($coalesce);
        if (isset($this->coalesceAssignLvalues[$coalesceId])) {
            $lvalueSlot = $block->slotForOperand($this->coalesceAssignLvalues[$coalesceId]);
            if (null !== $lvalueSlot) {
                return $lvalueSlot;
            }
        }
        if (isset($this->coalesceResultSlots[$coalesceId])) {
            return $this->coalesceResultSlots[$coalesceId];
        }
        $slot = $block->slotForOperand($coalesce->result);
        if (null !== $slot) {
            return $slot;
        }
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_COALESCE === $op->type) {
                    return $op->arg1;
                }
            }
            foreach ($current->parents as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    private function compileCallArgCoalesceSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): ?int {
        if ($this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
            return null;
        }
        if (
            null !== $cfgCallOp
            && null !== $argIndex
            && $this->headerScalarCallArgMustUseDirectOperand(
                $this->funcCallExprCalleeName($cfgCallOp),
                $argIndex
            )
        ) {
            return null;
        }
        if (
            null !== $cfgCallOp
            && null !== $argIndex
            && is_array($cfgCallOp->args ?? null)
            && isset($cfgCallOp->args[$argIndex])
            && $this->isCallArgUnrelatedToPriorStmtCoalesce($cfgCallOp->args[$argIndex])
        ) {
            return null;
        }
        $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null === $coalesce) {
            return null;
        }
        $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
        if (null === $coalesceSlot) {
            $this->compileCoalesce($coalesce, $block);
            $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
        }

        return $coalesceSlot;
    }

    private function resolvePropertyFetchCoalesceCallArgSlot(
        Op\Expr\PropertyFetch $producer,
        Op $callOp,
        Operand $arg,
        Block $block,
        ?int $argIndex = null
    ): ?int {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        foreach ($callOp->args as $callArg) {
            foreach ($this->findEmbeddedCoalesces($callArg) as $coalesce) {
                if ($this->findCoalescePropertyFetch($coalesce->left, $block) !== $producer) {
                    continue;
                }
                $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($coalesce, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
            $root = $this->unwrapOperandChain($callArg);
            if (
                $root instanceof Op\Expr\BinaryOp\Coalesce
                && $this->findCoalescePropertyFetch($root->left, $block) === $producer
            ) {
                $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($root, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
        }
        $coalesceStmt = $this->findCoalesceStmtForCallArg($arg, $block);
        if (
            null !== $coalesceStmt
            && $this->findCoalescePropertyFetch($coalesceStmt->left, $block) === $producer
        ) {
            return $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
        }

        return null;
    }

    private function resolveArrayDimFetchCoalesceCallArgSlot(
        Op\Expr\ArrayDimFetch $producer,
        Op $callOp,
        Operand $arg,
        Block $block,
        ?int $argIndex = null
    ): ?int {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        foreach ($callOp->args as $callArg) {
            foreach ($this->findEmbeddedCoalesces($callArg) as $coalesce) {
                if ($this->findCoalesceArrayDimFetch($coalesce->left, $block) !== $producer) {
                    continue;
                }
                $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($coalesce, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
            $root = $this->unwrapOperandChain($callArg);
            if (
                $root instanceof Op\Expr\BinaryOp\Coalesce
                && $this->findCoalesceArrayDimFetch($root->left, $block) === $producer
            ) {
                $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($root, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
        }
        $coalesceStmt = $this->findCoalesceStmtForCallArg($arg, $block);
        if (
            null !== $coalesceStmt
            && $this->findCoalesceArrayDimFetch($coalesceStmt->left, $block) === $producer
        ) {
            return $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
        }

        return null;
    }

    /**
     * @param list<Operand> $args
     */
    private function lowerEmbeddedCoalesceCallArgs(array $args, Block $block): void
    {
        foreach ($args as $arg) {
            foreach ($this->findEmbeddedCoalesces($arg) as $coalesce) {
                if (null === $this->slotForCoalesceResult($block, $coalesce)) {
                    $this->compileCoalesce($coalesce, $block);
                }
            }
            $stmtCoalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (
                null !== $stmtCoalesce
                && null === $this->slotForCoalesceResult($block, $stmtCoalesce)
            ) {
                $this->compileCoalesce($stmtCoalesce, $block);
            }
        }
    }
}
