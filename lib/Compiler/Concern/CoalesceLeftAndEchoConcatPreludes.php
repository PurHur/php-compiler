<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * Coalesce-left skip detectors (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers php-cfg ArrayDimFetch/PropertyFetch/StaticPropertyFetch emitted before
 * Coalesce (skip duplicate lowering). Binary-concat → ConcatList materialization
 * and "lowered by following echo/concat" prelude detection live in
 * {@see EchoConcatPreludes}.
 *
 * Companion to {@see EchoCoalesceCallArgCompile} (echo/?? → FuncCall arg wiring).
 * php-src: Zend/zend_compile.c (zend_compile_expr coalescing).
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
}
