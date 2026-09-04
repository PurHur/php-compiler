<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Coalesce-left skip + echo/concat prelude helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers php-cfg ArrayDimFetch/PropertyFetch/StaticPropertyFetch emitted before
 * Coalesce (skip duplicate lowering), binary-concat → ConcatList materialization,
 * and "lowered by following echo/concat" prelude detection used from compileOps.
 *
 * Companion to {@see EchoCoalesceCallArgCompile} (echo/?? → FuncCall arg wiring).
 * php-src: Zend/zend_compile.c (zend_compile_expr coalescing / concat / echo).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * and coalesce slot wiring relies on coercion (same as EchoCoalesceCallArgCompile).
 */
trait CoalesceLeftAndEchoConcatPreludes
{
    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Coalesce; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyCoalesceLeft(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    private function isPropertyFetchOnlyCoalesceLeft(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    /**
     * php-cfg emits StaticPropertyFetch as its own stmt before ?? / ??= (#31146).
     */
    private function isStaticPropertyFetchOnlyCoalesceLeft(
        Op\Expr\StaticPropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    /**
     * php-cfg emits StaticPropertyFetch as its own stmt before ?? / ??= (#31146).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingStaticPropertyFetchLeft(
        Op\Expr\StaticPropertyFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if ($this->isStaticPropertyFetchOnlyCoalesceLeft($fetch, $next)) {
                    return [$next, $j];
                }
                continue;
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            continue;
        }

        return null;
    }

    /**
     * php-cfg may emit RHS expr stmts between PropertyFetch and Coalesce (#8902).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingPropertyFetchLeft(
        Op\Expr\PropertyFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if ($this->isPropertyFetchOnlyCoalesceLeft($fetch, $next)) {
                    return [$next, $j];
                }
                // Nested ??= before outer ??= (e.g. $a->p ??= $b->q ??= 9) — keep scanning (#33760).
                continue;
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            // php-cfg hoists inner PropertyFetch / ??= stmts between outer fetch and ?? (#33760).
            continue;
        }

        return null;
    }

    private function isPropertyFetchOnlyCoalesceFuncCallArg(
        Op\Expr\PropertyFetch $fetch,
        Op $call,
        Block $block
    ): bool {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (null !== $coalesce && $this->findCoalescePropertyFetch($coalesce->left, $block) === $fetch) {
                return true;
            }
        }

        return false;
    }

    private function isArrayDimFetchOnlyCoalesceFuncCallArg(
        Op\Expr\ArrayDimFetch $fetch,
        Op $call,
        Block $block
    ): bool {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (null !== $coalesce && $this->findCoalesceArrayDimFetch($coalesce->left, $block) === $fetch) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg may emit RHS expr stmts (FuncCall, …) between ArrayDimFetch and Coalesce (#4416).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingArrayDimFetchLeft(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if (!$this->isArrayDimFetchOnlyCoalesceLeft($fetch, $next)) {
                    return null;
                }

                return [$next, $j];
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * php-cfg: ArrayDimFetch; Coalesce; Assign $dst = fetch-temp after ?? already stored in $dst.
     */
    private function isRedundantCoalesceTailAssign(
        Op\Expr\Assign $assign,
        Op\Expr\ArrayDimFetch $fetch,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->isCoalesceAssignTail($assign, $coalesce);
    }

    /**
     * php-cfg: Coalesce; Assign $dst = coalesce-result for ??= (issue #1235).
     */
    private function isCoalesceAssignTail(
        Op\Expr\Assign $assign,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->operandsChainEqual($assign->expr, $coalesce->result);
    }

    /**
     * php-cfg emits inner ?? before outer for chains ($a ?? $b ?? $c); only lower the outer stmt (#3798).
     *
     * @param Op[] $ops
     */
    private function isCoalesceChainInnerStmt(
        Op\Expr\BinaryOp\Coalesce $inner,
        array $ops,
        int $index
    ): bool {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $next = $ops[$index + 1];
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }

        return $this->operandsChainEqual($next->right, $inner->result);
    }

    /**
     * @return ?Op\Expr\ConcatList
     */
    private function unwrapConcatListExpr(Operand $operand): ?Op\Expr\ConcatList
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ConcatList) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ConcatList) {
            return $operand;
        }

        return null;
    }

    private function unwrapBinaryConcatExpr(Operand $operand): ?Op\Expr\BinaryOp\Concat
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\Concat) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Concat) {
            return $operand;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function resolveBinaryConcatForOperand(Operand $operand, array $ops): ?Op\Expr\BinaryOp\Concat
    {
        $concat = $this->unwrapBinaryConcatExpr($operand);
        if (null !== $concat) {
            return $concat;
        }
        foreach ($ops as $op) {
            if ($op instanceof Op\Expr\BinaryOp\Concat && $this->operandsChainEqual($op->result, $operand)) {
                return $op;
            }
        }

        return null;
    }

    /**
     * Flatten nested BinaryOp\Concat trees to one ConcatList so ?? branches do not split temps (#10430).
     *
     * @param Op[] $ops
     *
     * @return ?Op\Expr\ConcatList
     */
    private function flattenBinaryConcatFromBlockOps(array $ops, int $echoIndex, Operand $echoExpr): ?Op\Expr\ConcatList
    {
        $outer = null;
        for ($j = $echoIndex - 1; $j >= 0; --$j) {
            $candidate = $ops[$j] ?? null;
            if (
                $candidate instanceof Op\Expr\BinaryOp\Concat
                && $this->operandsChainEqual($candidate->result, $echoExpr)
            ) {
                $outer = $candidate;
                break;
            }
        }
        if (null === $outer) {
            return $this->flattenBinaryConcatToConcatList($echoExpr);
        }
        $parts = [];
        $current = $outer;
        while ($current instanceof Op\Expr\BinaryOp\Concat) {
            $parts[] = $current->right;
            $inner = $this->resolveBinaryConcatForOperand($current->left, $ops);
            if ($inner instanceof Op\Expr\BinaryOp\Concat) {
                $current = $inner;
                continue;
            }
            $parts[] = $current->left;
            break;
        }
        if (\count($parts) < 2) {
            return null;
        }
        $parts = array_reverse($parts);
        $list = new Op\Expr\ConcatList($parts, $outer->getAttributes());
        $list->result = $outer->result;

        return $list;
    }

    /**
     * @return ?Op\Expr\ConcatList
     */
    private function flattenBinaryConcatToConcatList(?Operand $operand): ?Op\Expr\ConcatList
    {
        if (null === $operand) {
            return null;
        }
        $parts = [];
        $current = $operand;
        $topConcat = null;
        while (null !== $current) {
            $concat = $this->unwrapBinaryConcatExpr($current);
            if (null === $concat) {
                $parts[] = $current;
                break;
            }
            if (null === $topConcat) {
                $topConcat = $concat;
            }
            $parts[] = $concat->right;
            $current = $concat->left;
        }
        if (\count($parts) < 2 || null === $topConcat) {
            return null;
        }
        $parts = array_reverse($parts);
        $list = new Op\Expr\ConcatList($parts, $topConcat->getAttributes());
        $list->result = $topConcat->result;

        return $list;
    }

    /**
     * @param Op[] $ops
     *
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function findBlockCoalescesBeforeIndex(array $ops, int $endIndex): array
    {
        $found = [];
        for ($j = 0; $j < $endIndex; ++$j) {
            if ($ops[$j] instanceof Op\Expr\BinaryOp\Coalesce) {
                $found[] = $ops[$j];
            }
        }

        return $found;
    }

    /**
     * Defer BinaryOp\Concat only when a following echo will lower pending ?? into the
     * concat (compileEchoWithEmbeddedCoalesce). Already-merged coalesces must not count —
     * otherwise a later `echo "x".$obj->prop` after `$a?->b ?? …` skips CONCAT and echoes
     * an empty temp (#25525 / re-#18455).
     *
     * @param Op[] $ops
     */
    private function isConcatLoweredByFollowingEcho(Op\Expr\BinaryOp\Concat $concat, array $ops, int $index): bool
    {
        $count = \count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                $coalesces = $this->findEmbeddedCoalesces($next->expr);
                if ([] === $coalesces) {
                    // Match compileEchoWithEmbeddedCoalesce: only pending ?? (#25525).
                    foreach ($this->findBlockCoalescesBeforeIndex($ops, $j) as $candidate) {
                        if (!isset($this->coalesceMergeBlocks[spl_object_id($candidate)])) {
                            $coalesces[] = $candidate;
                        }
                    }
                }
                if ([] === $coalesces) {
                    return false;
                }

                return null !== $this->flattenBinaryConcatFromBlockOps($ops, $j, $next->expr)
                    || null !== $this->unwrapConcatListExpr($next->expr)
                    || null !== $this->flattenBinaryConcatToConcatList($next->expr);
            }
            if ($next instanceof Op\Terminal\Return || $next instanceof Op\Expr\Assign) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    private function isCoalesceLoweredByFollowingEchoConcat(array $ops, int $index): bool
    {
        // ??= (coalesce + tail assign) must compile with ISSET/COALESCE branches — the echo reads
        // the array element via a separate dim fetch, not the coalesce result (#30435).
        if (
            $ops[$index] instanceof Op\Expr\BinaryOp\Coalesce
            && $index + 1 < \count($ops)
            && $ops[$index + 1] instanceof Op\Expr\Assign
            && $this->isCoalesceAssignTail($ops[$index + 1], $ops[$index])
        ) {
            return false;
        }
        for ($j = $index + 1; $j < \count($ops); ++$j) {
            if ($ops[$j] instanceof Op\Terminal\Echo_) {
                if (null !== $this->flattenBinaryConcatToConcatList($ops[$j]->expr)) {
                    return true;
                }
                if (null !== $this->flattenBinaryConcatFromBlockOps($ops, $j, $ops[$j]->expr)) {
                    return true;
                }

                return false;
            }
            if ($ops[$j] instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /**
     * echo var_export($arr['k'] ?? $d, true) . "\n" — defer call until ?? merge + concat echo (#18315).
     * Also `"prefix" . var_export($o->x ?? $d, true)` where the call is Concat.right (#31769).
     *
     * @param Op[] $ops
     */
    private function isFuncCallLoweredByFollowingEchoConcat(Op $call, array $ops, int $index): bool
    {
        if (
            !($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall)
            || !property_exists($call, 'result')
            || null === $call->result
        ) {
            return false;
        }
        $concat = $ops[$index + 1] ?? null;
        $concatAtNext = $concat instanceof Op\Expr\BinaryOp\Concat
            && (
                $this->operandsChainEqual($concat->left, $call->result)
                || $this->operandsReferToSameVariable($concat->left, $call->result)
                || $this->operandsChainEqual($concat->right, $call->result)
                || $this->operandsReferToSameVariable($concat->right, $call->result)
            );
        if (!$concatAtNext) {
            // set_error_handler() may sit between hoisted soft-null producer and echo (#21223).
            $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
            if (!$this->funcCallNameMaySoftNullDeprecateOnProfile84($name)) {
                return false;
            }

            return $this->hoistedSoftNullProducerHasFollowingEcho($ops, $index + 1);
        }
        for ($j = $index + 2; $j < \count($ops); ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                $flattened = $this->flattenBinaryConcatFromBlockOps($ops, $j, $next->expr)
                    ?? $this->flattenBinaryConcatToConcatList($next->expr);
                if (null === $flattened) {
                    return false;
                }
                $feedsEchoConcat = false;
                foreach ($flattened->list as $part) {
                    if (
                        null !== $part
                        && (
                            $this->operandsChainEqual($part, $call->result)
                            || $this->operandsReferToSameVariable($part, $call->result)
                        )
                    ) {
                        $feedsEchoConcat = true;
                        break;
                    }
                }
                if (!$feedsEchoConcat) {
                    return false;
                }
                for ($k = $index - 1; $k >= 0; --$k) {
                    $prev = $ops[$k];
                    if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                        return $this->isCoalesceLoweredByFollowingEchoConcat($ops, $k);
                    }
                    if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                        break;
                    }
                }

                return false;
            }
            if ($next instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /**
     * PROFILE≥8.4 soft-null hoisted producer — later echo must run set_error_handler first (#21223).
     *
     * @param Op[] $ops
     */
    private function hoistedSoftNullProducerHasFollowingEcho(array $ops, int $startIndex): bool
    {
        for ($j = $startIndex, $opCount = \count($ops); $j < $opCount; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                return true;
            }
            if ($next instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /** set_error_handler()/restore_error_handler() between hoisted producers and echo (#21223). */
    private function isErrorHandlerRegistrationStmt(Op $op): bool
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($op) ?? '');

        return \in_array($name, ['set_error_handler', 'restore_error_handler'], true);
    }

    /**
     * @param Op[] $ops
     */
    private function isConstFetchLoweredByFollowingEchoConcatFuncCall(array $ops, int $index): bool
    {
        $next = $ops[$index + 1] ?? null;
        if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
            return false;
        }

        return $this->isFuncCallLoweredByFollowingEchoConcat($next, $ops, $index + 1);
    }

    /**
     * Stmt-level ?? consumed by a FuncCall before echo-concat lowering runs (#18315, re-#11601).
     *
     * @param Op[] $ops
     */
    private function stmtCoalesceFeedsFuncCallBeforeEcho(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Op $callOp,
        array $ops,
        int $coalesceIndex,
        int $callIndex
    ): bool {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return false;
        }
        $resultOverride = $this->coalesceAssignLvalueOperand($coalesce);
        foreach ($callOp->args as $callArg) {
            if (
                null !== $callArg
                && !$this->isCallArgUnrelatedToPriorStmtCoalesce($callArg)
                && $this->callArgMatchesCoalesceExpressionValue($callArg, $coalesce, $resultOverride)
            ) {
                return true;
            }
        }
        $firstArg = $callOp->args[0] ?? null;
        if (
            null !== $firstArg
            && !$this->isCallArgUnrelatedToPriorStmtCoalesce($firstArg)
            && $this->onlyInlineCallArgProducersBetweenIndices($ops, $coalesceIndex, $callIndex)
            && (
                $this->callArgMatchesCoalesceExpressionValue($firstArg, $coalesce, $resultOverride)
                || $this->callArgIsDeadInlineTemporary($firstArg)
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Copy ?? branch results into merge-block temps so concat reads live CVs (#10430, #9973).
     */
    private function materializeConcatListCoalesceParts(Op\Expr\ConcatList $concat, Block $block): Op\Expr\ConcatList
    {
        $parts = [];
        foreach ($concat->list as $part) {
            if ($part instanceof Operand\Literal) {
                $parts[] = $part;
                continue;
            }
            $readSlot = $this->compileOperand($part, $block, true);
            $fresh = new Operand\Temporary();
            $writeSlot = $block->forceFreshVarSlot($fresh);
            $assignOp = new OpCode(
                OpCode::TYPE_ASSIGN,
                $writeSlot,
                $writeSlot,
                $readSlot
            );
            $this->assignConcatListSourceMetadata($assignOp, $concat);
            $block->addOpCode($assignOp);
            $parts[] = $fresh;
        }
        $materialized = new Op\Expr\ConcatList($parts, $concat->getAttributes());
        $materialized->result = $concat->result;

        return $materialized;
    }

    /**
     * php-cfg may embed Expr (e.g. spaceship) only under ConcatList / echo without a separate block op (#3671).
     */
    private function isExprLoweredInBlock(Op\Expr $expr, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->ops as $op) {
            if ($op === $expr) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower embedded expressions before reading operand slots (echo / concat paths).
     */
    private function compileEmbeddedExprForOperand(?Operand $operand, Block $block): void
    {
        if (null === $operand) {
            return;
        }
        if (!$operand instanceof Operand\Temporary || null === $operand->original) {
            return;
        }
        $original = $operand->original;
        if ($original instanceof Op\Expr && $this->isExprLoweredInBlock($original, $block)) {
            return;
        }
        if ($original instanceof Op\Expr\ConcatList) {
            $this->compileOp($original, $block);

            return;
        }
        if ($original instanceof Op\Expr) {
            $this->compileDeferredCoalesceBranchExpr($original, $block);
        }
    }

    private function compileConcatListPart(Operand $part, Block $block): int
    {
        $this->compileEmbeddedExprForOperand($part, $block);

        return $this->compileOperand($part, $block, true);
    }

    /**
     * CONCAT/CAST_STRING from encapsed ConcatList must carry the user site so
     * Undefined variable warnings do not inherit the prior statement's opline (#32034).
     *
     * php-src: Zend/zend_compile.c zend_compile_encapsed_string — FETCH_R lineno is the
     * interpolated expression, not the previous statement.
     */
    private function addConcatListOpCode(Block $block, OpCode $opcode, Op\Expr\ConcatList $concat): void
    {
        $this->assignConcatListSourceMetadata($opcode, $concat);
        $block->addOpCode($opcode);
    }

    private function assignConcatListSourceMetadata(OpCode $opcode, Op\Expr\ConcatList $concat): void
    {
        $this->assignSourceMetadata($opcode, $concat);
        $line = $this->concatListWarningLine($concat);
        if ($line <= 0) {
            return;
        }
        $loc = $opcode->sourceLocation;
        if (null !== $loc && $loc->startLine === $line) {
            return;
        }
        $opcode->sourceLocation = new SourceLocation(
            $loc?->docComment,
            $line,
            $loc?->endLine ?? max(0, (int) $concat->getAttribute('endLine', 0)),
            $loc?->filename ?? (string) $concat->getAttribute('filename', '')
        );
    }

    /**
     * Heredoc ConcatList startLine is the `<<<LABEL` opener; Zend FETCH_R cites the body
     * (php-parser String_::KIND_HEREDOC === 3, #32034).
     */
    private function concatListWarningLine(Op\Expr\ConcatList $concat): int
    {
        $start = max(0, $concat->getLine());
        $end = max(0, (int) $concat->getAttribute('endLine', 0));
        $kind = (int) $concat->getAttribute('kind', 0);
        if (3 === $kind && $end > $start && $start > 0) {
            return $start + 1;
        }

        return $start;
    }

    /** Concat destination must not alias an active catch variable slot (#17384). */
    private function concatResultSlotAliasesCatchVar(int $slot): bool
    {
        if ([] === $this->activeCatchVarSlotsByName) {
            return false;
        }

        return \in_array($slot, $this->activeCatchVarSlotsByName, true);
    }

    private function freshConcatResultSlotIfCatchAlias(int $slot, Block $block, Operand $result): int
    {
        if (!$this->concatResultSlotAliasesCatchVar($slot)) {
            return $slot;
        }

        return $block->forceFreshVarSlot($result);
    }

    /**
     * @return ?Op\Expr\BinaryOp\Coalesce
     */
    private function unwrapCoalesceExpr(Operand $operand): ?Op\Expr\BinaryOp\Coalesce
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\Coalesce) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Coalesce) {
            return $operand;
        }

        return null;
    }

    private function operandsChainEqual(Operand $a, Operand $b): bool
    {
        while ($a instanceof Temporary) {
            if ($a === $b) {
                return true;
            }
            if (null === $a->original) {
                break;
            }
            $a = $a->original;
        }
        while ($b instanceof Temporary) {
            if ($b === $a) {
                return true;
            }
            if (null === $b->original) {
                break;
            }
            $b = $b->original;
        }

        return $a === $b;
    }

    private function findFuncCallFirstArgOperand(CfgFunc $func, string $name): ?Operand
    {
        $found = null;
        $walk = function ($node) use (&$walk, $name, &$found): void {
            if (null !== $found) {
                return;
            }
            if ($node instanceof Op\Expr\FuncCall) {
                $fn = $node->name;
                if ($fn instanceof Literal && $name === $fn->value && isset($node->args[0])) {
                    $found = $node->args[0];

                    return;
                }
            }
            if ($node instanceof CfgBlock) {
                foreach ($node->children as $child) {
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
            if ($node instanceof Op\Stmt\Loop) {
                $walk($node->loop);
            }
            if ($node instanceof Op\Stmt\Foreach_) {
                $walk($node->loop);
            }
        };
        $walk($func->cfg);

        return $found;
    }

}
