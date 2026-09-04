<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Echo + coalesce→FuncCall arg wiring (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers echo-with-embedded-??, chained coalesce FuncCall arg rewire/sync,
 * and ??= result-temp sync helpers used from compileOps / compileExpr.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; coalesce
 * slot wiring relies on coercion (same as CoalesceAndNullsafe / CompileCallArgSends).
 */
trait EchoCoalesceCallArgCompile
{
    /**
     * Echo with embedded ?? / ??= must use compileCoalesce and continue on the merge block (#99, #1960, #1980).
     *
     * @param Op[] $ops
     */
    private function compileEchoWithEmbeddedCoalesce(Op $op, Block $block, array $ops, int $echoIndex): ?Block
    {
        if (!$op instanceof Op\Terminal || 'Terminal_Echo' !== $op->getType()) {
            return null;
        }
        // php-cfg: BinaryOp\Coalesce child immediately before Terminal_Echo(expr=coalesce.result).
        if ($echoIndex >= 1) {
            $prior = $ops[$echoIndex - 1];
            if (
                $prior instanceof Op\Expr\BinaryOp\Coalesce
                && $this->operandsChainEqual($op->expr, $prior->result)
            ) {
                $var = $this->compileOperand($prior->result, $block, true);
                $line = $op->getLine();
                $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var, $line > 0 ? $line : null));

                return $block;
            }
        }
        $echoAfterAssign = $this->resolveEchoAfterCoalesceAssign($ops, $echoIndex, $op->expr);
        if (null !== $echoAfterAssign && $this->isStmtCoalesceLoweredBeforeEcho($ops, $echoIndex)) {
            $var = $this->compileOperand($echoAfterAssign, $block, true);
            $line = $op->getLine();
            $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var, $line > 0 ? $line : null));

            return $block;
        }
        $coalesces = $this->findEmbeddedCoalesces($op->expr);
        if ([] === $coalesces) {
            // Block-scan fallback: only pending ?? — already-merged ones rewind CFG to a
            // prior merge and strand later nullsafe/?? after TYPE_NULLSAFE (#19591 / #18455).
            foreach ($this->findBlockCoalescesBeforeIndex($ops, $echoIndex) as $candidate) {
                if (isset($this->coalesceMergeBlocks[spl_object_id($candidate)])) {
                    continue;
                }
                $coalesces[] = $candidate;
            }
        }
        if ([] === $coalesces) {
            return null;
        }
        $flattened = $this->flattenBinaryConcatFromBlockOps($ops, $echoIndex, $op->expr)
            ?? $this->unwrapConcatListExpr($op->expr)
            ?? $this->flattenBinaryConcatToConcatList($op->expr);
        $echoOperand = $op->expr;
        $coalesceSnapshots = [];
        foreach ($coalesces as $coalesce) {
            $resultOverride = $this->findCoalesceAssignTarget($ops, $coalesce)
                ?? $this->findEchoCoalesceAssignTarget($ops, $echoIndex, $coalesce);
            if (null === $this->slotForCoalesceResult($block, $coalesce)) {
                $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
            }
            $coalesceId = spl_object_id($coalesce);
            if (isset($this->coalesceMergeBlocks[$coalesceId])) {
                $block = $this->coalesceMergeBlocks[$coalesceId];
            }
            if (null !== $resultOverride) {
                $coalesceSnapshots[] = [$coalesce, $resultOverride];
                continue;
            }
            $snapshot = new Operand\Temporary();
            $readSlot = $this->compileOperand($coalesce->result, $block, true);
            $writeSlot = $block->forceFreshVarSlot($snapshot);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $writeSlot,
                $writeSlot,
                $readSlot
            ));
            $coalesceSnapshots[] = [$coalesce, $snapshot];
            if (
                null === $flattened
                && (
                    $this->operandsChainEqual($echoOperand, $coalesce->result)
                    || $echoOperand === $coalesce
                    || (
                        null !== ($embeddedCoalesce = $this->unwrapCoalesceExpr($echoOperand))
                        && $embeddedCoalesce === $coalesce
                    )
                )
            ) {
                $echoOperand = $snapshot;
            }
        }
        if (null !== $flattened) {
            $block = $this->compileEchoConcatFuncCallPreludes($ops, $echoIndex, $flattened, $block);
        }
        $concat = $flattened;
        if (null !== $concat) {
            $parts = [];
            foreach ($concat->list as $part) {
                $replaced = $part;
                foreach ($coalesceSnapshots as [$coalesce, $replacement]) {
                    if (
                        $this->operandsChainEqual($part, $coalesce->result)
                        || $this->operandsChainEqual($part, $replacement)
                        || $part === $coalesce
                        || (
                            null !== ($embeddedCoalesce = $this->unwrapCoalesceExpr($part))
                            && $embeddedCoalesce === $coalesce
                        )
                    ) {
                        $replaced = $replacement;
                        break;
                    }
                }
                $parts[] = $replaced;
            }
            $concat = new Op\Expr\ConcatList($parts, $flattened->getAttributes());
            $concat->result = $flattened->result;
            $this->compileOp($concat, $block);
            $var = $this->compileOperand($concat->result, $block, true);
        } else {
            $var = $this->compileOperand($echoOperand, $block, true);
            $var = $this->resolveEchoEmitSlot($echoOperand, $block, $var);
        }
        $line = $op->getLine();
        $echoOpcode = new OpCode(OpCode::TYPE_ECHO, $var, $line > 0 ? $line : null);
        $this->attachEchoScriptGlobalName($echoOpcode, $echoOperand, $block);
        $block->addOpCode($echoOpcode);

        return $block;
    }

    /**
     * Compile FuncCall preludes that feed echo-concat operands after ?? merge blocks (#18315).
     *
     * @param Op[] $ops
     */
    private function compileEchoConcatFuncCallPreludes(
        array $ops,
        int $echoIndex,
        Op\Expr\ConcatList $flattened,
        Block $block
    ): Block {
        $neededResults = [];
        foreach ($flattened->list as $part) {
            if (null !== $part) {
                $neededResults[] = $part;
            }
        }
        for ($j = 0; $j < $echoIndex; ++$j) {
            $child = $ops[$j];
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'result')
                || null === $child->result
            ) {
                continue;
            }
            $feedsConcat = false;
            foreach ($neededResults as $needed) {
                if (
                    $this->operandsChainEqual($needed, $child->result)
                    || $this->operandsReferToSameVariable($needed, $child->result)
                ) {
                    $feedsConcat = true;
                    break;
                }
            }
            if (!$feedsConcat || null !== $block->slotForOperand($child->result)) {
                continue;
            }
            for ($k = $j - 1; $k >= 0; --$k) {
                $prev = $ops[$k];
                if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                    break;
                }
                if (
                    $prev instanceof Op\Expr
                    && $this->isInlineExprCallArgProducer($prev)
                    && null !== $prev->result
                    && null === $block->slotForOperand($prev->result)
                ) {
                    $this->compileOp($prev, $block);
                }
            }
            $this->compileOp($child, $block);
        }

        return $block;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) — echo the ??= lvalue (#1980).
     *
     * @param Op[] $ops
     */
    private function resolveEchoAfterCoalesceAssign(array $ops, int $echoIndex, Operand $echoExpr): ?Operand
    {
        if ($echoIndex < 2) {
            return null;
        }
        $assign = $ops[$echoIndex - 1];
        $coalesce = $ops[$echoIndex - 2];
        if (!$assign instanceof Op\Expr\Assign || !$coalesce instanceof Op\Expr\BinaryOp\Coalesce) {
            if (
                $echoIndex >= 3
                && $ops[$echoIndex - 1] instanceof Op\Expr\Assign
                && $ops[$echoIndex - 2] instanceof Op\Expr\ArrayDimFetch
                && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce
            ) {
                /** @var Op\Expr\Assign $assign */
                $assign = $ops[$echoIndex - 1];
                /** @var Op\Expr\ArrayDimFetch $fetch */
                $fetch = $ops[$echoIndex - 2];
                /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                $coalesce = $ops[$echoIndex - 3];
                if (
                    $this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)
                    && $this->operandsChainEqual($echoExpr, $coalesce->result)
                ) {
                    return $assign->var;
                }
            }

            return null;
        }
        if (
            $this->isCoalesceAssignTail($assign, $coalesce)
            && (
                $this->operandsChainEqual($echoExpr, $coalesce->result)
                || $this->operandsChainEqual($echoExpr, $assign->var)
            )
        ) {
            return $assign->var;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isStmtCoalesceLoweredBeforeEcho(array $ops, int $echoIndex): bool
    {
        if ($echoIndex >= 1 && $ops[$echoIndex - 1] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }
        if ($echoIndex >= 2 && $ops[$echoIndex - 2] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }
        if ($echoIndex >= 3 && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }

        return false;
    }

    /**
     * php-cfg: Coalesce immediately followed by Assign(expr=coalesce.result).
     *
     * @param Op[] $ops
     */
    private function findCoalesceAssignTarget(array $ops, Op\Expr\BinaryOp\Coalesce $coalesce): ?Operand
    {
        $count = \count($ops);
        for ($j = 0; $j < $count - 1; ++$j) {
            if ($ops[$j] !== $coalesce) {
                continue;
            }
            $next = $ops[$j + 1];
            if ($next instanceof Op\Expr\Assign && $this->isCoalesceAssignTail($next, $coalesce)) {
                return $next->var;
            }

            return null;
        }

        return null;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) for inline ??= (#1980).
     *
     * @param Op[] $ops
     */
    private function findEchoCoalesceAssignTarget(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): ?Operand {
        $direct = $this->findCoalesceAssignTarget($ops, $coalesce);
        if (null !== $direct) {
            return $direct;
        }
        if ($echoIndex > 0) {
            $prev = $ops[$echoIndex - 1];
            if ($prev instanceof Op\Expr\Assign && $this->isCoalesceAssignTail($prev, $coalesce)) {
                return $prev->var;
            }
        }
        if (
            $echoIndex > 2
            && $ops[$echoIndex - 2] instanceof Op\Expr\Assign
            && $ops[$echoIndex - 3] instanceof Op\Expr\ArrayDimFetch
        ) {
            /** @var Op\Expr\Assign $assign */
            $assign = $ops[$echoIndex - 2];
            /** @var Op\Expr\ArrayDimFetch $fetch */
            $fetch = $ops[$echoIndex - 3];
            if ($this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)) {
                return $assign->var;
            }
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isCoalesceLoweredBeforeEcho(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        for ($j = $echoIndex - 1; $j >= 0; --$j) {
            if ($ops[$j] === $coalesce) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function findEmbeddedCoalesces(Operand $operand): array
    {
        $found = [];
        $seen = [];
        $add = function (Op\Expr\BinaryOp\Coalesce $coalesce) use (&$found, &$seen): void {
            $id = spl_object_id($coalesce);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $found[] = $coalesce;
        };
        $coalesce = $this->unwrapCoalesceExpr($operand);
        if (null !== $coalesce) {
            $add($coalesce);
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\BinaryOp\Coalesce) {
            $add($root);
        }
        $concat = $this->unwrapConcatListExpr($operand);
        if (null !== $concat) {
            foreach ($concat->list as $part) {
                foreach ($this->findEmbeddedCoalesces($part) as $nested) {
                    $add($nested);
                }
            }
        }
        $binaryConcat = $this->unwrapBinaryConcatExpr($operand);
        if (null !== $binaryConcat) {
            foreach ($this->findEmbeddedCoalesces($binaryConcat->left) as $nested) {
                $add($nested);
            }
            foreach ($this->findEmbeddedCoalesces($binaryConcat->right) as $nested) {
                $add($nested);
            }
        }

        return $found;
    }

    /**
     * Chained ?? call args: compile coalesce into a temp, then call on the merge block (#17380).
     *
     * @return ?list<OpCode>
     */
    private function compileFuncCallAfterChainedCoalesceArgs(Op\Expr\FuncCall $expr, Block $block): ?array
    {
        $newArgs = $expr->args;
        $changed = false;
        /** @var array<int, int> $chainedCoalesceArgTempSlots */
        $chainedCoalesceArgTempSlots = [];
        /** @var array<int, int> $chainedCoalesceArgMergeSources */
        $chainedCoalesceArgMergeSources = [];
        foreach ($expr->args as $i => $arg) {
            $coalesce = $this->findChainedCoalesceForCallArg($arg, $block, $expr, (int) $i);
            if (null === $coalesce) {
                continue;
            }
            $mergeSlot = $this->coalesceResultSlots[spl_object_id($coalesce)]
                ?? $this->slotForCoalesceResult($block, $coalesce);
            if (null === $mergeSlot) {
                continue;
            }
            $callArg = $expr->args[$i] ?? $arg;
            $callBlock = $this->coalesceMergeBlocks[spl_object_id($coalesce)] ?? $block;
            // Snapshot ?? merge into the php-cfg call-arg temp on the call block (#17590 AOT).
            $argSendSlot = $this->compileOperand($callArg, $callBlock, false);
            $this->registerSyncedCoalesceFuncCallArgSlot($arg, $argSendSlot);
            $this->registerSyncedCoalesceFuncCallArgSlot($callArg, $argSendSlot);
            $newArgs[$i] = $callArg;
            $chainedCoalesceArgTempSlots[(int) $i] = $argSendSlot;
            $chainedCoalesceArgMergeSources[(int) $i] = $mergeSlot;
            $changed = true;
        }
        if (!$changed) {
            return null;
        }
        $callBlock = $block;
        foreach ($expr->args as $i => $arg) {
            $coalesce = $this->findChainedCoalesceForCallArg($arg, $block, $expr, (int) $i);
            if (null !== $coalesce && isset($this->coalesceMergeBlocks[spl_object_id($coalesce)])) {
                $callBlock = $this->coalesceMergeBlocks[spl_object_id($coalesce)];
                break;
            }
        }
        $callOps = $this->compileFuncCall(
            $this->compileOperand($expr->name, $callBlock, true),
            $newArgs,
            $expr->result,
            $callBlock,
            max(0, $expr->getLine()),
            $expr
        );
        if ([] !== $chainedCoalesceArgTempSlots) {
            $callOps = $this->rewireChainedCoalesceFuncCallArgSends($callOps, $chainedCoalesceArgTempSlots);
        }
        foreach ($chainedCoalesceArgMergeSources as $argIdx => $mergeSlot) {
            $sendSlot = $chainedCoalesceArgTempSlots[(int) $argIdx];
            $callBlock->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $sendSlot,
                $sendSlot,
                $mergeSlot
            ));
        }
        foreach ($callOps as $op) {
            $callBlock->addOpCode($op);
        }
        foreach ($chainedCoalesceArgTempSlots as $argIdx => $tempSlot) {
            $this->patchBlockChainedCoalesceFuncCallArgSend($callBlock, (int) $argIdx, $tempSlot);
        }

        return [];
    }

    private function patchBlockChainedCoalesceFuncCallArgSend(Block $block, int $argIndex, int $tempSlot): void
    {
        $inCall = false;
        $sendIdx = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $inCall = true;
                $sendIdx = 0;
                continue;
            }
            if (!$inCall || OpCode::TYPE_ARG_SEND !== $op->type) {
                if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    $inCall = false;
                }
                continue;
            }
            if ($sendIdx === $argIndex) {
                $op->arg1 = (string) $tempSlot;
            }
            ++$sendIdx;
        }
    }

    /**
     * @param list<OpCode>     $callOps
     * @param array<int, int> $argTempSlots
     *
     * @return list<OpCode>
     */
    private function rewireChainedCoalesceFuncCallArgSends(array $callOps, array $argTempSlots): array
    {
        $inCall = false;
        $sendIdx = 0;
        foreach ($callOps as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $inCall = true;
                $sendIdx = 0;
                continue;
            }
            if (!$inCall || OpCode::TYPE_ARG_SEND !== $op->type) {
                if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    $inCall = false;
                }
                continue;
            }
            if (isset($argTempSlots[$sendIdx])) {
                $op->arg1 = (string) $argTempSlots[$sendIdx];
            }
            ++$sendIdx;
        }

        return $callOps;
    }

    private function findChainedCoalesceForCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?Op\Expr\BinaryOp\Coalesce {
        foreach ($this->findEmbeddedCoalesces($arg) as $coalesce) {
            if ($this->coalesceRhsIsNestedCoalesce($coalesce, $block)) {
                return $coalesce;
            }
        }
        if ($this->callArgReadsAssignLvalueNotInlineCoalesce($arg, $block)) {
            return null;
        }
        $stmtCoalesce = $this->findOutermostChainedCoalesceStmtForCallArg($arg, $block, $cfgCallOp, $argIndex);
        if (null !== $stmtCoalesce) {
            return $stmtCoalesce;
        }
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null)) {
            $cfgArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $cfgArg && $cfgArg !== $arg) {
                foreach ($this->findEmbeddedCoalesces($cfgArg) as $coalesce) {
                    if ($this->coalesceRhsIsNestedCoalesce($coalesce, $block)) {
                        return $coalesce;
                    }
                }
            }
        }

        return null;
    }

    private function findOutermostChainedCoalesceStmtForCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?Op\Expr\BinaryOp\Coalesce {
        $inner = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null === $inner && null !== $cfgCallOp && is_array($cfgCallOp->args ?? null)) {
            $cfgArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $cfgArg && $cfgArg !== $arg) {
                $inner = $this->findCoalesceStmtForCallArg($cfgArg, $block);
            }
        }
        if (null === $inner || null === $block->orig) {
            return null !== $inner && $this->coalesceRhsIsNestedCoalesce($inner, $block) ? $inner : null;
        }
        $outermost = $inner;
        foreach ($block->orig->children as $op) {
            if (!$op instanceof Op\Expr\BinaryOp\Coalesce) {
                continue;
            }
            if (!$this->coalesceRhsIsNestedCoalesce($op, $block)) {
                continue;
            }
            if (
                $this->operandsChainEqual($op->right, $inner->result)
                || $this->operandsReferToSameVariable($op->right, $inner->result)
                || $op->right === $inner
            ) {
                $outermost = $op;
            }
        }

        return $this->coalesceRhsIsNestedCoalesce($outermost, $block) ? $outermost : null;
    }

    private function coalesceRhsIsNestedCoalesce(Op\Expr\BinaryOp\Coalesce $coalesce, Block $block): bool
    {
        $rhsRoot = $this->unwrapOperandChain($coalesce->right);
        if ($rhsRoot instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }

        return $this->findOrigExprOpForOperand($coalesce->right, $block) instanceof Op\Expr\BinaryOp\Coalesce;
    }

    /**
     * True when $arg is an assign lvalue fed by a prior stmt-level ?? (#17590).
     *
     * compileFuncCallAfterChainedCoalesceArgs must not re-lower ?? for var_export($v) after $v = … ?? ….
     */
    private function callArgReadsAssignLvalueNotInlineCoalesce(Operand $arg, Block $block): bool
    {
        if ($this->callArgIsDirectChainedCoalesceMergeTarget($arg)) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        // No stmt-level ?? → no coalesce-assign tail ahead of a call arg (#36387).
        if (!$this->cfgBlockHasCoalesceStmt($block->orig)) {
            return false;
        }
        foreach ($block->orig->children as $i => $child) {
            if (
                !($child instanceof Op\Expr\Assign)
                || !$this->operandsReferToSameVariable($child->var, $arg)
            ) {
                continue;
            }
            if (
                0 === $i
                || !($block->orig->children[$i - 1] instanceof Op\Expr\BinaryOp\Coalesce)
            ) {
                continue;
            }
            /** @var Op\Expr\BinaryOp\Coalesce $priorCoalesce */
            $priorCoalesce = $block->orig->children[$i - 1];

            return $this->isCoalesceAssignTail($child, $priorCoalesce);
        }

        return false;
    }

    /** True when ?? was lowered with resultOverride = php-cfg call-arg temp (#17590). */
    private function callArgIsDirectChainedCoalesceMergeTarget(Operand $arg): bool
    {
        foreach ($this->coalesceAssignLvalues as $lvalue) {
            if (
                $this->operandsReferToSameVariable($lvalue, $arg)
                || $this->operandsChainEqual($lvalue, $arg)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg call-arg temp for chained ?? before FuncCall — mirror assign-then-call (#17590).
     */
    private function resolveChainedCoalesceCallArgOverride(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        int $coalesceIdx
    ): ?Operand {
        if (null === $block->orig || !$this->followingFuncCallHasChainedCoalesceArg($block, $coalesceIdx)) {
            return null;
        }
        $children = $block->orig->children;
        for ($j = $coalesceIdx + 1, $count = \count($children); $j < $count; ++$j) {
            $next = $children[$j];
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                if (!property_exists($next, 'args') || !\is_array($next->args)) {
                    return null;
                }
                foreach ($next->args as $argIndex => $arg) {
                    if (null === $arg) {
                        continue;
                    }
                    $outermost = $this->findOutermostChainedCoalesceStmtForCallArg(
                        $arg,
                        $block,
                        $next,
                        (int) $argIndex
                    );
                    if ($outermost === $coalesce) {
                        return $next->args[$argIndex];
                    }
                }

                return null;
            }
            if ($next instanceof Op\Expr && $this->isInlineExprCallArgProducer($next)) {
                continue;
            }

            return null;
        }

        return null;
    }

    private function coalesceStmtDeferredToChainedFuncCallArg(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $idx = $this->cfgCallOpIndexInChildren($block->orig->children, $coalesce, $block->orig);

        return \is_int($idx) && $this->followingFuncCallHasChainedCoalesceArg($block, $idx);
    }

    /**
     * Inner ?? stmt whose result feeds outer chained ?? deferred to FuncCall (#17590).
     */
    private function coalesceStmtFeedsDeferredChainedFuncCallArg(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $idx = $this->cfgCallOpIndexInChildren($block->orig->children, $coalesce, $block->orig);
        if (!\is_int($idx)) {
            return false;
        }
        $children = $block->orig->children;
        for ($j = $idx + 1, $count = \count($children); $j < $count; ++$j) {
            $next = $children[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if (
                    $this->operandsChainEqual($next->right, $coalesce->result)
                    || $this->operandsReferToSameVariable($next->right, $coalesce->result)
                    || $next->right === $coalesce
                ) {
                    return $this->coalesceStmtDeferredToChainedFuncCallArg($next, $block);
                }

                return false;
            }
            if ($next instanceof Op\Expr && $this->isInlineExprCallArgProducer($next)) {
                continue;
            }

            return false;
        }

        return false;
    }

    private function followingFuncCallHasChainedCoalesceArg(Block $block, int $fromIdx): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $children = $block->orig->children;
        for ($j = $fromIdx + 1, $count = \count($children); $j < $count; ++$j) {
            $next = $children[$j];
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                if (!property_exists($next, 'args') || !\is_array($next->args)) {
                    return false;
                }
                foreach ($next->args as $argIndex => $arg) {
                    if (null !== $arg
                        && null !== $this->findChainedCoalesceForCallArg($arg, $block, $next, (int) $argIndex)
                    ) {
                        return true;
                    }
                }

                return false;
            }
            if ($next instanceof Op\Expr && $this->isInlineExprCallArgProducer($next)) {
                continue;
            }

            return false;
        }

        return false;
    }

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
