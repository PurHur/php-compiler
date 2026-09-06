<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Echo + chained coalesce→FuncCall arg rewire (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers echo-with-embedded-?? and chained coalesce FuncCall arg rewire.
 * Stmt-level ??= / ?? → FuncCall arg sync helpers live in
 * {@see StmtCoalesceFuncCallArgSyncCompile}.
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

}
