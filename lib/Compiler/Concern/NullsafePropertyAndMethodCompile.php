<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\Block;

/**
 * Nullsafe (`?->`) chain detect / skip / write-context reject helpers (#36387 / #36403).
 *
 * Companion to {@see NullsafeChainCompile} (sync + property/dim/method lowering).
 * Find/collect/shouldSkip plus prelude/reject helpers stay here so gen-0 split-TU
 * can hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c nullsafe
 * compile — move-only; no behavior change intended.
 *
 * Visibility stays protected where LintCompiler / call sites require it.
 */
trait NullsafePropertyAndMethodCompile
{
    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChain(?Operand $operand, Block $block): array
    {
        $innermost = $this->findNullsafePropertyFetch($operand, $block);
        if (null === $innermost) {
            return [];
        }
        $chain = [$innermost];
        $var = $innermost->var;
        while (true) {
            $prev = $this->findNullsafePropertyFetchProducing($var, $block);
            if (null === $prev) {
                break;
            }
            array_unshift($chain, $prev);
            $var = $prev->var;
        }

        return $chain;
    }

    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChainForEmpty(Op\Expr\Empty_ $expr, Block $block): array
    {
        $operand = $this->unaryExprOperandForRead($expr, $block);
        if (null === $operand) {
            return [];
        }

        return $this->collectNullsafePropertyFetchChain($operand, $block);
    }

    /**
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetch(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetchProducing(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $operand) {
                return $child;
            }
        }
        if ($operand instanceof Temporary && null !== $operand->original) {
            return $this->findNullsafePropertyFetchProducing($operand->original, $block);
        }

        return null;
    }

    /**
     * php-cfg Temporary.original is often null; locate NullsafeMethodCall by result operand (#19591).
     *
     * @return ?Op\Expr\NullsafeMethodCall
     */
    protected function findNullsafeMethodCallProducing(?Operand $operand, Block $block): ?Op\Expr\NullsafeMethodCall
    {
        if (null === $operand || null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\NullsafeMethodCall
                && (
                    $child->result === $operand
                    || $this->operandsChainEqual($child->result, $operand)
                )
            ) {
                return $child;
            }
        }
        if ($operand instanceof Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\NullsafeMethodCall) {
                return $operand->original;
            }

            return $this->findNullsafeMethodCallProducing($operand->original, $block);
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafePropertyFetchForIssetOrEmpty(
        Op\Expr\NullsafePropertyFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\Isset_ && 1 === count($next->vars)) {
                $chain = $this->collectNullsafePropertyFetchChain($next->vars[0], $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }
            if ($next instanceof Op\Expr\Empty_) {
                $chain = $this->collectNullsafePropertyFetchChainForEmpty($next, $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }

            return false;
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafePropertyFetchForCoalesce(
        Op\Expr\NullsafePropertyFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                $chain = $this->collectNullsafePropertyFetchChain($next->left, $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }

            return false;
        }

        return false;
    }

    /**
     * Defer $a->b?->m() when it feeds a following ?? so coalesce can continue on the
     * nullsafe merge block (#19591).
     *
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafeMethodCallForCoalesce(
        Op\Expr\NullsafeMethodCall $call,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                return $this->operandsChainEqual($next->left, $call->result)
                    || $next->left === $call->result;
            }
            if ($next instanceof Op\Expr\NullsafePropertyFetch || $next instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg lowers $a->b?->v as PropertyFetch then NullsafePropertyFetch — skip eager fetch (#16637).
     *
     * @param Op[] $ops
     */
    private function isPropertyFetchNullsafeReceiver(
        Op\Expr\PropertyFetch $fetch,
        array $ops,
        int $index
    ): bool {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $next = $ops[$index + 1];

        if (
            $next instanceof Op\Expr\NullsafePropertyFetch
            && $this->operandsChainEqual($next->var, $fetch->result)
        ) {
            return true;
        }

        // $a->b?->m() — receiver fetch is emitted inside compileNullsafeMethodCall / coalesce (#19591).
        return $next instanceof Op\Expr\NullsafeMethodCall
            && $this->operandsChainEqual($next->var, $fetch->result);
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function nullsafePreludeOperandVars(Op\Expr $expr): array
    {
        // Minimal dependency extraction for nullsafe argument prelude sinking (#4394).
        // Extend carefully; keep conservative (only single-use temporaries are eligible).
        return match (get_class($expr)) {
            Op\Expr\FuncCall::class => array_merge([$expr->name], $expr->args),
            Op\Expr\Closure::class => [],
            default => [],
        };
    }

    /**
     * True when a nullsafe call-arg temporary is the producer result or a parseArg clone
     * whose ops still reference that producer (#8560, #22660).
     */
    private function nullsafeCallArgTempFedByProducer(Operand $argTemp, Op\Expr $producer): bool
    {
        if ($argTemp === $producer->result || $this->operandsReferToSameVariable($argTemp, $producer->result)) {
            return true;
        }
        if (!$argTemp instanceof Operand\Temporary) {
            return false;
        }
        foreach ($argTemp->ops ?? [] as $embedded) {
            if ($embedded === $producer) {
                return true;
            }
        }

        return false;
    }

    private function isNullsafeMethodCallArgPreludeProducer(Op\Expr $expr): bool
    {
        return $expr instanceof Op\Expr\FuncCall
            || $expr instanceof Op\Expr\NsFuncCall
            || $expr instanceof Op\Expr\Closure
            || $expr instanceof Op\Expr\New_
            || $expr instanceof Op\Expr\MethodCall
            || $expr instanceof Op\Expr\StaticCall;
    }

    /**
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @param Op[] $ops
     */
    private function isNullsafePropertyFetchInWriteContext(array $ops, int $index): bool
    {
        $fetch = $ops[$index] ?? null;
        if (!$fetch instanceof Op\Expr\NullsafePropertyFetch) {
            return false;
        }

        return $this->operandUsedInWriteContext($ops, $index + 1, $fetch->result);
    }

    /**
     * Zend zend_compile.c: &$nullsafeChain is a distinct compile fatal from write-context (#26638).
     *
     * php-cfg hoists NullsafePropertyFetch / NullsafeMethodCall before AssignRef; the result may
     * feed AssignRef.expr directly or via PropertyFetch / ArrayDimFetch / further nullsafe hops.
     *
     * @param Op[] $ops
     */
    private function isNullsafeOperandUsedAsAssignRefRhs(array $ops, int $startIndex, Operand $operand): bool
    {
        for ($j = $startIndex, $count = count($ops); $j < $count; ++$j) {
            $op = $ops[$j];
            if ($op instanceof Op\Expr\AssignRef && $this->operandsChainEqual($op->expr, $operand)) {
                return true;
            }
            if ($op instanceof Op\Expr\NullsafePropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\PropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\ArrayDimFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->isNullsafeOperandUsedAsAssignRefRhs($ops, $j + 1, $op->result);
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: Cannot take reference of a nullsafe chain (#26638).
     */
    protected function rejectNullsafeReferenceAcquisition(?Operand $expr, ?Block $block = null): void
    {
        if (null === $expr) {
            return;
        }
        if ($this->rvalueContainsNullsafeChain($expr, $block)) {
            $this->throwCompileError('Cannot take reference of a nullsafe chain');
        }
    }

    /**
     * True when AssignRef RHS resolves to a ?-> property/method (or chain thereof).
     */
    protected function rvalueContainsNullsafeChain(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\NullsafePropertyFetch
                || $operand->original instanceof Op\Expr\NullsafeMethodCall) {
                return true;
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $this->rvalueContainsNullsafeChain($operand->original->var, $block);
            }
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $this->rvalueContainsNullsafeChain($operand->original->var, $block);
            }
            if (null === $operand->original) {
                break;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\NullsafePropertyFetch
            || $operand instanceof Op\Expr\NullsafeMethodCall) {
            return true;
        }
        if ($operand instanceof Op\Expr\PropertyFetch || $operand instanceof Op\Expr\ArrayDimFetch) {
            return $this->rvalueContainsNullsafeChain($operand->var, $block);
        }
        if (null !== $block && null !== $block->orig) {
            if ($this->operandIsNullsafePropertyFetchResult($operand, $block->orig->children)
                || $this->operandIsNullsafeMethodCallResult($operand, $block->orig->children)) {
                return true;
            }
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                return $this->rvalueContainsNullsafeChain($propFetch->var, $block);
            }
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    private function operandIsNullsafeMethodCallResult(?Operand $operand, array $ops): bool
    {
        if (null === $operand) {
            return false;
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }
            if ($this->operandsChainEqual($child->result, $operand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg result temps for Expr ops do not chain `original` back to the producer (#5323).
     *
     * @param Op[] $ops
     */
    private function operandIsNullsafePropertyFetchResult(?Operand $operand, array $ops): bool
    {
        if (null === $operand) {
            return false;
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($this->operandsChainEqual($child->result, $operand)) {
                return true;
            }
        }

        return false;
    }

    protected function lvalueContainsNullsafePropertyFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\NullsafePropertyFetch) {
                return true;
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if (null === $operand->original) {
                break;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\NullsafePropertyFetch) {
            return true;
        }
        if ($operand instanceof Op\Expr\PropertyFetch) {
            return $this->lvalueContainsNullsafePropertyFetch($operand->var, $block);
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $this->lvalueContainsNullsafePropertyFetch($operand->var, $block);
        }
        if (null !== $block && null !== $block->orig) {
            if ($this->operandIsNullsafePropertyFetchResult($operand, $block->orig->children)) {
                return true;
            }
            // php-cfg result temps omit `original`; resolve PropertyFetch producer for chains (#25560).
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($propFetch->var, $block);
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @return never
     */
    protected function rejectNullsafeInWriteContext(?Operand $var, ?Block $block = null): void
    {
        if ($this->lvalueContainsNullsafePropertyFetch($var, $block)) {
            $this->throwCompileError("Can't use nullsafe operator in write context");
        }
        if (null !== $block && null !== $block->orig && null !== $var) {
            $dimFetch = $this->unwrapArrayDimFetch($var);
            if (null !== $dimFetch
                && $this->operandIsNullsafePropertyFetchResult($dimFetch->var, $block->orig->children)) {
                $this->throwCompileError("Can't use nullsafe operator in write context");
            }
        }
    }

}
