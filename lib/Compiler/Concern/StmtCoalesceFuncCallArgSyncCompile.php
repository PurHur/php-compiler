<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Stmt-level ?? / ??= → FuncCall arg sync helpers (#36387 / #36403).
 *
 * Extracted from {@see EchoCoalesceCallArgCompile} so gen-0 split-TU can hollow
 * a smaller Concern TU. Covers compileCoalesceForAssign, synced coalesce slot
 * register/resolve, and stmt-coalesce ordinal wiring used from compileOps /
 * CompileCallArgSends. Mirrors php-src Zend/zend_compile.c coalesce / ?? and
 * ZEND_SEND_* arg temp binding.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; coalesce
 * slot wiring relies on coercion (same as EchoCoalesceCallArgCompile).
 */
trait StmtCoalesceFuncCallArgSyncCompile
{
    private function compileCoalesceForAssign(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        if (null !== $resultOverride) {
            // ??= skips compileExpr(Assign); enforce the same write-context guards (#29247).
            $this->rejectNullsafeInWriteContext($resultOverride, $block);
            $this->rejectNewExprInWriteContext($resultOverride, $block, null, null, $coalesce);
            $this->rejectArrayLiteralInWriteContext($resultOverride, $block, $coalesce);
            $this->rejectGlobalConstInWriteContext($resultOverride, $block, $coalesce);
            $this->rejectCallReturnInWriteContext($resultOverride, $block, $coalesce);
        }
        if (null === $resultOverride) {
            $dimFetch = $this->findCoalesceArrayDimFetch($coalesce->left, $block);
            if (null !== $dimFetch && $this->operandsChainEqual($coalesce->result, $dimFetch->result)) {
                $resultOverride = $dimFetch->result;
            } elseif ($this->operandsChainEqual($coalesce->result, $coalesce->left)) {
                $resultOverride = $coalesce->left;
            }
        }

        if (
            null !== $resultOverride
            && !$this->operandsChainEqual($resultOverride, $coalesce->result)
        ) {
            $this->coalesceAssignLvalues[spl_object_id($coalesce)] = $resultOverride;
        }

        $block = $this->compileCoalesce($coalesce, $block, $resultOverride);

        // Tail `$local = $… ?? …` skips the Assign that would registerNamedAssignDest (#24540).
        // Without that CV binding, later SSA reads of $local (is_array ? … / if bodies) allocate
        // fresh undefined slots while the coalesce merge slot still holds the value.
        if (null !== $resultOverride) {
            $varRoot = Block::cfgVarRoot($resultOverride);
            $cvName = null !== $varRoot ? Block::resolveVariableName($varRoot) : null;
            if (null !== $varRoot && null !== $cvName && '' !== $cvName) {
                $cvSlot = $this->coalesceResultSlots[spl_object_id($coalesce)]
                    ?? $this->compileOperand($resultOverride, $block, false);
                $block->registerNamedAssignDest($varRoot, (int) $cvSlot);
            }
        }

        // php-cfg keeps a separate coalesce result temp when ??= is an expression (#5337, #17458).
        // Skip when echo reads resultOverride directly — syncing would null the override slot (TYPE_ASSIGN).
        if (
            $this->coalesceAssignNeedsResultTempSync($coalesce, $resultOverride, $block)
            || $this->coalesceAssignHasFollowingCallExpressionConsumer($coalesce, $resultOverride, $block)
        ) {
            $resultSlot = $this->compileOperand($coalesce->result, $block, false);
            $overrideSlot = $this->compileOperand($resultOverride, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $overrideSlot
            ));
        }

        $this->syncCoalesceResultToDistinctFuncCallArg($coalesce, $block, $resultOverride);

        return $block;
    }

    private function registerSyncedCoalesceFuncCallArgSlot(Operand $targetArg, int $slot): void
    {
        $this->syncedCoalesceFuncCallArgSlots['oid:' . spl_object_id($targetArg)] = $slot;
        $root = Block::cfgVarRoot($targetArg);
        if (null !== $root) {
            $this->syncedCoalesceFuncCallArgSlots[spl_object_id($root)] = $slot;
        }
    }

    private function resolveSyncedCoalesceFuncCallArgSlot(Operand $arg): ?int
    {
        $oidKey = 'oid:' . spl_object_id($arg);
        if (isset($this->syncedCoalesceFuncCallArgSlots[$oidKey])) {
            return $this->syncedCoalesceFuncCallArgSlots[$oidKey];
        }
        $root = Block::cfgVarRoot($arg);
        if (null !== $root && isset($this->syncedCoalesceFuncCallArgSlots[spl_object_id($root)])) {
            return $this->syncedCoalesceFuncCallArgSlots[spl_object_id($root)];
        }

        return null;
    }

    /** True when a call arg reads stmt-level or embedded ?? (merge block must keep coalesce slot, #16127). */
    private function callArgIsCoalesceMergeProducer(
        ?Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): bool {
        if (null === $arg) {
            return false;
        }
        if (
            null !== $this->resolveSyncedCoalesceFuncCallArgSlot($arg)
            || null !== $this->findCoalesceStmtForCallArg($arg, $block)
        ) {
            return true;
        }
        if (null !== $cfgCallOp && null !== $argIndex) {
            $probe = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $probe && $probe !== $arg) {
                return $this->callArgIsCoalesceMergeProducer($probe, $block);
            }
        }

        return false;
    }

    /**
     * Stmt-level ?? immediately before a FuncCall (only inline producers in between, #11601, #15915).
     */
    private function findStmtCoalesceImmediatelyBeforeFuncCall(Op $callOp, Block $block): ?Op\Expr\BinaryOp\Coalesce
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (
            null === $callIndex
            && property_exists($callOp, 'result')
            && null !== $callOp->result
        ) {
            foreach ($children as $i => $child) {
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && null !== $child->result
                    && (
                        $child->result === $callOp->result
                        || $this->operandsReferToSameVariable($child->result, $callOp->result)
                    )
                ) {
                    $callIndex = $i;
                    break;
                }
            }
        }
        if (null === $callIndex) {
            return null;
        }
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $prev = $children[$j];
            if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                return $prev;
            }
            // var_dump(array_keys($arr['k'] ?? [])) — ?? feeds the inner call only (#15946).
            if (
                $j === $callIndex - 1
                && ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
            ) {
                return null;
            }
            if ($prev instanceof Op\Expr && $this->isInlineExprCallArgProducer($prev)) {
                continue;
            }

            break;
        }

        return null;
    }

    /**
     * Stmt-level ?? nodes immediately feeding a FuncCall, in execution order (#17981).
     *
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function stmtLevelCoalescesBeforeFuncCall(Op $callOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (
            null === $callIndex
            && property_exists($callOp, 'result')
            && null !== $callOp->result
        ) {
            foreach ($children as $i => $child) {
                if (
                    ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                    && null !== $child->result
                    && (
                        $child->result === $callOp->result
                        || $this->operandsReferToSameVariable($child->result, $callOp->result)
                    )
                ) {
                    $callIndex = $i;
                    break;
                }
            }
        }
        if (null === $callIndex) {
            return [];
        }
        $coalesces = [];
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $prev = $children[$j];
            if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                array_unshift($coalesces, $prev);

                continue;
            }
            if (
                $prev instanceof Op\Expr\Assign
                && $j > 0
            ) {
                $maybeCoalesce = $children[$j - 1];
                if (
                    $maybeCoalesce instanceof Op\Expr\BinaryOp\Coalesce
                    && $this->isCoalesceAssignTail($prev, $maybeCoalesce)
                ) {
                    array_unshift($coalesces, $maybeCoalesce);
                    --$j;

                    continue;
                }
            }
            if ($prev instanceof Op\Expr && $this->isInlineExprCallArgProducer($prev)) {
                continue;
            }

            break;
        }

        return $coalesces;
    }

    /**
     * Call-arg indices fed by distinct stmt-level ?? temps (not embedded ?? or literals, #17981).
     *
     * @return list<int>
     */
    private function stmtCoalesceFedCallArgIndices(Op $callOp): array
    {
        if (!property_exists($callOp, 'args') || !\is_array($callOp->args)) {
            return [];
        }
        $fedArgIndices = [];
        foreach ($callOp->args as $idx => $callArg) {
            if (null === $callArg || $this->isCallArgUnrelatedToPriorStmtCoalesce($callArg)) {
                continue;
            }
            if ([] !== $this->findEmbeddedCoalesces($callArg)) {
                continue;
            }
            $fedArgIndices[] = (int) $idx;
        }

        return $fedArgIndices;
    }

    /**
     * Map a stmt-level ?? to its matching php-cfg call-arg temp when multiple ?? precede one call (#17981).
     */
    private function findCallArgForStmtCoalesce(
        Op $callOp,
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block
    ): ?Operand {
        $coalesces = $this->stmtLevelCoalescesBeforeFuncCall($callOp, $block);
        $coalesceIndex = array_search($coalesce, $coalesces, true);
        if (false === $coalesceIndex || \count($coalesces) < 2) {
            return null;
        }
        $fedArgIndices = $this->stmtCoalesceFedCallArgIndices($callOp);
        if (\count($fedArgIndices) !== \count($coalesces)) {
            return null;
        }

        return $callOp->args[$fedArgIndices[$coalesceIndex] ?? -1] ?? null;
    }

    /**
     * Map a php-cfg call-arg temp to its stmt-level ?? when multiple ?? precede one call (#17981).
     */
    private function findStmtCoalesceForOrdinalCallArg(
        Op $callOp,
        Operand $matchedCallArg,
        Block $block
    ): ?Op\Expr\BinaryOp\Coalesce {
        $coalesces = $this->stmtLevelCoalescesBeforeFuncCall($callOp, $block);
        if (\count($coalesces) < 2) {
            return null;
        }
        $fedArgIndices = $this->stmtCoalesceFedCallArgIndices($callOp);
        if (\count($fedArgIndices) !== \count($coalesces)) {
            return null;
        }
        foreach ($fedArgIndices as $ordinal => $argIdx) {
            $callArg = $callOp->args[$argIdx] ?? null;
            if (
                null !== $callArg
                && (
                    $callArg === $matchedCallArg
                    || $this->operandsReferToSameVariable($callArg, $matchedCallArg)
                )
            ) {
                return $coalesces[$ordinal] ?? null;
            }
        }

        return null;
    }

    private function callArgHasPriorStmtCoalesce(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null !== $this->findCoalesceStmtForCallArg($arg, $block)) {
            return true;
        }
        if (null !== $this->resolveSyncedCoalesceFuncCallArgSlot($arg)) {
            return true;
        }
        if (
            0 === $argIndex
            && null !== $cfgCallOp
            && null !== ($stmtCoalesce = $this->findStmtCoalesceImmediatelyBeforeFuncCall($cfgCallOp, $block))
        ) {
            $firstArg = $cfgCallOp->args[0] ?? null;
            $resultOverride = $this->coalesceAssignLvalueOperand($stmtCoalesce);
            $hasOtherCoalesceFedArg = false;
            foreach ($cfgCallOp->args ?? [] as $idx => $otherArg) {
                if ((int) $idx === $argIndex || null === $otherArg) {
                    continue;
                }
                if (
                    [] !== $this->findEmbeddedCoalesces($otherArg)
                    || $this->callArgMatchesCoalesceExpressionValue($otherArg, $stmtCoalesce, $resultOverride)
                ) {
                    $hasOtherCoalesceFedArg = true;
                    break;
                }
            }
            // Named first args (gettype($a) after `$a['x'] ??…`) are containers, not the ?? value (#29112).
            if (
                !$hasOtherCoalesceFedArg
                && null !== $firstArg
                && !$this->isEmbeddedCallLiteralArg($arg)
                && (
                    $this->callArgMatchesCoalesceExpressionValue($arg, $stmtCoalesce, $resultOverride)
                    || (
                        $this->callArgIsDeadInlineTemporary($firstArg)
                        && $this->operandsReferToSameVariable($firstArg, $arg)
                    )
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stmt-level ?? before multi-arg calls must win over hoisted dim-fetch producer slots (#11601, #15915).
     */
    private function finalizeStmtCoalesceCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        ?string $valueSlot
    ): ?string {
        if ($this->isCallArgDirectArrayDimFetch($arg)) {
            return $valueSlot;
        }
        $callArg = (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null))
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        foreach (array_filter([$callArg, $arg]) as $probe) {
            $syncedSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($probe);
            if (null !== $syncedSlot) {
                return (string) $syncedSlot;
            }
        }
        $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null === $coalesce && null !== $cfgCallOp && 0 === $argIndex) {
            $stmtCoalesce = $this->findStmtCoalesceImmediatelyBeforeFuncCall($cfgCallOp, $block);
            if (null !== $stmtCoalesce) {
                $firstArg = $cfgCallOp->args[0] ?? null;
                $resultOverride = $this->coalesceAssignLvalueOperand($stmtCoalesce);
                $hasOtherCoalesceFedArg = false;
                foreach ($cfgCallOp->args ?? [] as $idx => $otherArg) {
                    if ((int) $idx === $argIndex || null === $otherArg) {
                        continue;
                    }
                    if (
                        [] !== $this->findEmbeddedCoalesces($otherArg)
                        || $this->callArgMatchesCoalesceExpressionValue($otherArg, $stmtCoalesce, $resultOverride)
                    ) {
                        $hasOtherCoalesceFedArg = true;
                        break;
                    }
                }
                if (
                    !$hasOtherCoalesceFedArg
                    && null !== $firstArg
                    && !$this->isEmbeddedCallLiteralArg($arg)
                    && (
                        $this->callArgMatchesCoalesceExpressionValue($arg, $stmtCoalesce, $resultOverride)
                        || (
                            $this->callArgIsDeadInlineTemporary($firstArg)
                            && $this->operandsReferToSameVariable($firstArg, $arg)
                        )
                    )
                ) {
                    $coalesce = $stmtCoalesce;
                }
            }
        }
        if (null !== $coalesce) {
            $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
            if (null !== $coalesceSlot) {
                return (string) $coalesceSlot;
            }
        }

        return $valueSlot;
    }

    /**
     * True when $callArg is the ?? / ??= expression value — not the dim/prop container (#29112).
     *
     * Matching coalesce->left is unsafe: for `$a['x'] ?? …` / `??=` the left is the element
     * temp, and a following `gettype($a)` / `json_encode($a)` must keep the array/object CV.
     */
    private function callArgMatchesCoalesceExpressionValue(
        Operand $callArg,
        Op\Expr\BinaryOp\Coalesce $coalesce,
        ?Operand $resultOverride
    ): bool {
        if (
            $this->operandsChainEqual($callArg, $coalesce->result)
            || $this->operandsReferToSameVariable($callArg, $coalesce->result)
        ) {
            return true;
        }
        if (
            null !== $resultOverride
            && (
                $this->operandsChainEqual($callArg, $resultOverride)
                || $this->operandsReferToSameVariable($callArg, $resultOverride)
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Resolve ??= / `$dst = … ?? …` assign destination recorded for a coalesce stmt (#29112).
     */
    private function coalesceAssignLvalueOperand(Op\Expr\BinaryOp\Coalesce $coalesce): ?Operand
    {
        return $this->coalesceAssignLvalues[spl_object_id($coalesce)] ?? null;
    }

    /**
     * php-cfg may allocate a distinct temp for FuncCall args vs Coalesce->result (#9479, enum_int_cast_warning.phpt).
     */
    private function syncCoalesceResultToDistinctFuncCallArg(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        ?Operand $resultOverride
    ): void {
        if (null === $block->orig) {
            return;
        }
        $ops = $block->orig->children;
        $coalesceIdx = null;
        foreach ($ops as $idx => $op) {
            if ($op === $coalesce) {
                $coalesceIdx = $idx;
                break;
            }
        }
        if (null === $coalesceIdx) {
            return;
        }
        for ($j = $coalesceIdx + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            // Only the ??= tail Assign may sit between coalesce and an immediate call.
            // Skipping general inline producers walked across later `$a["k"] ??= …` / `$a = …`
            // and froze a pre-mutation snapshot into the call arg (#29145, re-#28954 shape).
            if (
                $next instanceof Op\Expr\Assign
                && $this->isCoalesceAssignTail($next, $coalesce)
            ) {
                continue;
            }
            if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
                return;
            }
            if (!property_exists($next, 'args') || !is_array($next->args) || [] === $next->args) {
                return;
            }
            foreach ($next->args as $embeddedArgProbe) {
                if (null === $embeddedArgProbe) {
                    continue;
                }
                foreach ($this->findEmbeddedCoalesces($embeddedArgProbe) as $embedded) {
                    if ($embedded === $coalesce) {
                        return;
                    }
                }
            }
            $targetArg = null;
            foreach ($next->args as $callArg) {
                if (
                    null === $callArg
                    || $this->isCallArgUnrelatedToPriorStmtCoalesce($callArg)
                ) {
                    continue;
                }
                if ($this->callArgMatchesCoalesceExpressionValue($callArg, $coalesce, $resultOverride)) {
                    $targetArg = $callArg;
                    break;
                }
            }
            if (null === $targetArg) {
                $ordinalTarget = $this->findCallArgForStmtCoalesce($next, $coalesce, $block);
                if (null !== $ordinalTarget) {
                    $targetArg = $ordinalTarget;
                } else {
                    // First-arg fallback is only for php-cfg dead expression temps
                    // (`foo($a['x'] ?? 'y')`), never named containers like gettype($a) (#29112).
                    $firstArg = $next->args[0] ?? null;
                    if (
                        null !== $firstArg
                        && !$this->isCallArgUnrelatedToPriorStmtCoalesce($firstArg)
                        && $this->onlyInlineCallArgProducersBetweenIndices($ops, $coalesceIdx, $j)
                        && (
                            $this->callArgMatchesCoalesceExpressionValue($firstArg, $coalesce, $resultOverride)
                            || $this->callArgIsDeadInlineTemporary($firstArg)
                        )
                    ) {
                        $targetArg = $firstArg;
                    }
                }
            }
            if (null === $targetArg) {
                return;
            }
            $syncReadOperand = $this->coalesceAssignHasFollowingCallExpressionConsumer(
                $coalesce,
                $resultOverride,
                $block
            )
                ? $coalesce->result
                : ($resultOverride ?? $coalesce->result);
            // Wire the post-?? merge result slot — not an inner dim-fetch temp (#10743, #15915).
            $this->registerSyncedCoalesceFuncCallArgSlot(
                $targetArg,
                $this->compileOperand($syncReadOperand, $block, true)
            );

            return;
        }
    }

    /**
     * Stmt-level ??= immediately before FuncCall — expression value lives in a dead arg temp (#5337, #17458).
     *
     * "Immediately" means only the ??= tail Assign may intervene. Do not skip ArrayDimFetch /
     * later ??= / other writes — those mutate the lvalue and the call must read the live CV (#29145).
     */
    private function coalesceAssignHasFollowingCallExpressionConsumer(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        ?Operand $resultOverride,
        Block $block
    ): bool {
        if (
            null === $resultOverride
            || null === $block->orig
            || $this->operandsChainEqual($resultOverride, $coalesce->result)
        ) {
            return false;
        }
        $ops = $block->orig->children;
        $coalesceIdx = null;
        foreach ($ops as $idx => $op) {
            if ($op === $coalesce) {
                $coalesceIdx = $idx;
                break;
            }
        }
        if (null === $coalesceIdx) {
            return false;
        }
        for ($j = $coalesceIdx + 1, $count = \count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Assign && $this->isCoalesceAssignTail($next, $coalesce)) {
                continue;
            }
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                return true;
            }
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                return true;
            }
            // Echo / dim ??= / plain assign / anything else: not an expression-value call consumer.
            return false;
        }

        return false;
    }

    /**
     * True when ??= coalesce->result is read outside echo/tail-assign paths (#5337).
     */
    private function coalesceAssignNeedsResultTempSync(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        ?Operand $resultOverride,
        Block $block
    ): bool {
        if (
            null === $resultOverride
            || $this->operandsChainEqual($resultOverride, $coalesce->result)
        ) {
            return false;
        }
        if (null === $block->orig) {
            return true;
        }

        $ops = $block->orig->children;
        $tailAssign = null;
        foreach ($ops as $idx => $op) {
            if ($op !== $coalesce) {
                continue;
            }
            if (
                isset($ops[$idx + 1])
                && $ops[$idx + 1] instanceof Op\Expr\Assign
                && $this->isCoalesceAssignTail($ops[$idx + 1], $coalesce)
            ) {
                $tailAssign = $ops[$idx + 1];
            }
            break;
        }

        foreach ($ops as $op) {
            if ($op instanceof Op\Expr\Assign) {
                if ($op === $tailAssign) {
                    continue;
                }
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    return true;
                }
            }
            if ($op instanceof Op\Terminal\Echo) {
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    continue;
                }
            }
            if ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall) {
                foreach ($op->args as $arg) {
                    if ($this->operandsChainEqual($arg, $coalesce->result)) {
                        return true;
                    }
                }
            }
            if ($op instanceof Op\Expr\MethodCall || $op instanceof Op\Expr\StaticCall) {
                foreach ($op->args as $arg) {
                    if ($this->operandsChainEqual($arg, $coalesce->result)) {
                        return true;
                    }
                }
            }
            if ($op instanceof Op\Terminal\Return_ && null !== $op->expr) {
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    return true;
                }
            }
            if ($op instanceof Op\Expr\BinaryOp\Coalesce && $op !== $coalesce) {
                if ($this->operandsChainEqual($op->right, $coalesce->result)) {
                    return true;
                }
            }
        }

        return false;
    }

}
