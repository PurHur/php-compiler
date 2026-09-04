<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Try/finally merge rewrites, catch-var slots, and operand/lvalue unwrap lookup (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers finally-before-merge jump rewrites (#2114 / #25240), catch type/slot
 * resolution, isset/dim/property/static-property operand unwraps, and class-name
 * / static-property name slot helpers used from ClassLikeAndStmtCompile and
 * compileOperand.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait TryFinallyCatchAndOperandLookup
{

    /**
     * Normal try/catch completion must run finally before merge; php-cfg jumps straight to end (#2114, #195).
     * Also rewrite nested blocks and JUMPIF→merge leave edges so AOT matches VM (#25240 / #35547).
     * Non-merge leaves (break → loop exit) still need an AOT leave trampoline; VM unwinds those.
     */
    private function rewriteMergeJumpsToFinally(Block $source, Block $merge, Block $finally): void
    {
        $seen = [];
        $this->rewriteMergeJumpsToFinallyRecursive($source, $merge, $finally, $seen);
    }

    /**
     * @param array<int, true> $seen
     */
    private function rewriteMergeJumpsToFinallyRecursive(
        Block $source,
        Block $merge,
        Block $finally,
        array &$seen
    ): void {
        $id = spl_object_id($source);
        if (isset($seen[$id]) || $source === $merge || $source === $finally) {
            return;
        }
        $seen[$id] = true;
        for ($i = 0; $i < $source->nOpCodes; ++$i) {
            $op = $source->opCodes[$i];
            if (OpCode::TYPE_JUMP === $op->type && $op->block1 === $merge) {
                $op->block1 = $finally;
            } elseif (OpCode::TYPE_JUMPIF === $op->type) {
                // continue / fallthrough leave: JumpIf arm targets merge (#25240 / #35547).
                if ($op->block1 === $merge) {
                    $op->block1 = $finally;
                }
                if ($op->block2 === $merge) {
                    $op->block2 = $finally;
                }
            }
            if (null !== $op->block1) {
                $this->rewriteMergeJumpsToFinallyRecursive($op->block1, $merge, $finally, $seen);
            }
            if (null !== $op->block2) {
                $this->rewriteMergeJumpsToFinallyRecursive($op->block2, $merge, $finally, $seen);
            }
        }
    }

    /**
     * Try/catch merge blocks from php-cfg may include later sibling try/catch in the same end
     * block. JIT pre-lowers merge at beginTry via compileIncludedAtEntry; nested TYPE_TRY in
     * that merge corrupts LLVM EH basic blocks (#4041). Split so merge is prefix-only + JUMP.
     *
     * When the nested TYPE_TRY is at index 0 (two sequential try/catch, nothing between), still
     * split into an empty merge prefix that JUMP's to the nested try — otherwise the first
     * catch falls into the second try's EH and the second catch sees the first exception (#23930).
     */
    private function splitMergeBeforeNestedTry(Block $merge): Block
    {
        $splitAt = null;
        for ($i = 0; $i < $merge->nOpCodes; ++$i) {
            $type = $merge->opCodes[$i]->type;
            if (
                OpCode::TYPE_TRY === $type
                || OpCode::TYPE_CATCH === $type
                || OpCode::TYPE_FINALLY === $type
            ) {
                $splitAt = $i;
                break;
            }
        }
        if (null === $splitAt) {
            return $merge;
        }
        $tailOps = \array_slice($merge->opCodes, $splitAt);
        $merge->opCodes = \array_slice($merge->opCodes, 0, $splitAt);
        $merge->nOpCodes = \count($merge->opCodes);
        $merge->invalidateOpcodeDerivedIndexes();
        $tail = $merge->fragmentForOpcodes($tailOps);
        $tail->orig = $merge->orig;
        $tail->inheritUndefinedLocals = $merge->inheritUndefinedLocals;
        $jump = new OpCode(OpCode::TYPE_JUMP);
        $jump->block1 = $tail;
        $merge->addOpCode($jump);

        return $merge;
    }

    /**
     * php-cfg TryCatch emits a Stmt_Jump into the try body; TYPE_TRY already enters it (#2084).
     */
    private function isRedundantTryEntryJump(Block $block, Block $target): bool
    {
        for ($i = $block->nOpCodes - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                continue;
            }
            if (OpCode::TYPE_TRY === $op->type) {
                return $op->block1 === $target;
            }

            break;
        }

        return false;
    }

    protected function encodeCatchTypeList(array $types): string
    {
        $encoded = [];
        foreach ($types as $name) {
            // Intersection arms arrive as a single `A&B` member from php-cfg (#28205).
            if (str_contains($name, '&')) {
                $parts = [];
                foreach (explode('&', $name) as $part) {
                    $parts[] = strtolower(ltrim($part, '\\'));
                }
                $encoded[] = implode('&', $parts);
            } else {
                $encoded[] = strtolower(ltrim($name, '\\'));
            }
        }

        return implode('|', $encoded);
    }

    /**
     * php-cfg catch vars are registered on the handler block; the catch body may use
     * a distinct operand for the same name (#195, #2084, #3445).
     */
    protected function resolveCatchVarSlot(Block $compiledCatch, ?Operand $catchVar): ?int
    {
        if (null === $catchVar) {
            return null;
        }
        $slot = $compiledCatch->slotForOperand($catchVar);
        if (null !== $slot) {
            return $slot;
        }
        if (null !== $this->resolveCatchVariableName($catchVar)) {
            // Catch body may reference $e only from nested try blocks (#195, #2084).
            return $compiledCatch->getVarSlot($catchVar, false);
        }

        return null;
    }

    protected function resolveCatchVariableName(?Operand $catchVar): ?string
    {
        while ($catchVar instanceof Operand\Temporary && null !== $catchVar->original) {
            $catchVar = $catchVar->original;
        }
        if (!$catchVar instanceof Operand\Variable) {
            return null;
        }
        $nameOp = $catchVar->name;
        while ($nameOp instanceof Operand\Temporary && null !== $nameOp->original) {
            $nameOp = $nameOp->original;
        }
        if ($nameOp instanceof Literal && is_string($nameOp->value)) {
            return $nameOp->value;
        }

        return null;
    }

    private function slotForActiveCatchVariable(?Operand $operand): ?int
    {
        if ([] === $this->activeCatchVarSlotsByName || null === $operand) {
            return null;
        }
        $name = $this->resolveCatchVariableName($operand);
        if (null !== $name) {
            $slot = $this->activeCatchVarSlotsByName[strtolower($name)] ?? null;
            if (null !== $slot) {
                return $slot;
            }
        }
        $root = Block::cfgVarRoot($operand);
        if (null === $root) {
            return null;
        }
        foreach ($this->activeCatchVarRoots as $catchRoot) {
            if ($catchRoot === $root) {
                $catchName = $this->resolveCatchVariableName($catchRoot);
                if (null === $catchName) {
                    return null;
                }

                return $this->activeCatchVarSlotsByName[strtolower($catchName)] ?? null;
            }
        }

        return null;
    }

    /**
     * @param Op\Expr|Operand $expr
     *
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTarget($expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($expr, $block);
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return [
                $this->compileOperand($expr->var, $block, true),
                $this->compileOperand($expr->name, $block, true),
            ];
        }
        if ($expr instanceof Operand) {
            $fetch = $this->unwrapArrayDimFetch($expr);
            if (null !== $fetch) {
                return [
                    $this->compileOperand($fetch->var, $block, true),
                    $this->compileOperand($fetch->dim, $block, true),
                ];
            }
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $expr) {
                    return [
                        $this->compileOperand($child->var, $block, true),
                        $this->compileOperand($child->name, $block, true),
                    ];
                }
            }
            $canonical = $this->unwrapVariableOperand($expr);

            return [$this->compileOperand(null !== $canonical ? $canonical : $expr, $block, true), null];
        }

        $this->throwCompileLogic('Unsupported isset target: ' . (is_object($expr) ? $expr->getType() : gettype($expr)));
    }

    /**
     * Reject `$arr[]` in read context — Zend compile fatal (#12303, zend_language_parser.y).
     */
    protected function rejectArrayEmptyOffsetRead(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        if (!$this->isArrayAppendDim($fetch->dim)) {
            return;
        }
        if ($this->isArrayDimFetchForWrite($fetch, $block)) {
            return;
        }
        // String match ErrorSuppressAndPropertyFetch::ARRAY_EMPTY_OFFSET_READ_COMPILE_ERROR
        // (private trait const is not visible across sibling Concerns).
        $this->throwCompileError('Cannot use [] for reading');
    }

    /** True for `$arr[]` append syntax — php-cfg uses {@see NullOperand}, not PHP null (#12303). */
    protected function isArrayAppendDim(?Operand $dim): bool
    {
        // Plain null dim means php-cfg lost the index operand (comma-for `$a[$i]` in for-init, #1492).
        return $dim instanceof NullOperand;
    }

    /**
     * php-cfg Expr::result temporaries omit ->original; match list-destruct fetch by result (#3799).
     */
    protected function findArrayDimFetchForResult(Operand $result, Block $block): ?Op\Expr\ArrayDimFetch
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $result) {
                return $child;
            }
        }
        // php-cfg may allocate a distinct FuncCall arg temp whose sole writer is the dim
        // fetch (`f([1,2][0])` — arg !== ArrayDimFetch->result) (#29522).
        $writer = $result->ops[0] ?? null;
        if ($writer instanceof Op\Expr\ArrayDimFetch) {
            return $writer;
        }

        return null;
    }

    /**
     * php-cfg Expr::result temporaries omit ->original; match inline array literal RHS (#3799).
     */
    protected function findArrayExprForResult(Operand $result, Block $block): ?Op\Expr\Array_
    {
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_ && $child->result === $result) {
                return $child;
            }
        }

        return null;
    }

    private function cfgExprUsesOperand(Op\Expr $expr, Operand $operand): bool
    {
        if ($expr instanceof Op\Expr\Array_) {
            foreach ($expr->values as $value) {
                if (null === $value) {
                    continue;
                }
                if ($value === $operand || $this->operandsReferToSameVariable($value, $operand)) {
                    return true;
                }
            }
            foreach ($expr->keys as $key) {
                if (null === $key) {
                    continue;
                }
                if ($key === $operand || $this->operandsReferToSameVariable($key, $operand)) {
                    return true;
                }
            }

            return false;
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $expr->left === $operand
                || $expr->right === $operand
                || $this->operandsReferToSameVariable($expr->left, $operand)
                || $this->operandsReferToSameVariable($expr->right, $operand);
        }
        if ($expr instanceof Op\Expr\InstanceOf_) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\NullsafePropertyFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\NullsafeMethodCall) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            return $expr->class === $operand
                || $this->operandsReferToSameVariable($expr->class, $operand);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $expr->class === $operand
                || $this->operandsReferToSameVariable($expr->class, $operand);
        }
        // new Outer(new Inner(..., Class::CONST), …) — ClassConstFetch feeds inner New_ args (#19439).
        // php-cfg may rewrite fetch->result into a distinct Temporary on the New_ arg list; link via $arg->ops.
        if (
            $expr instanceof Op\Expr\New_
            || $expr instanceof Op\Expr\FuncCall
            || $expr instanceof Op\Expr\NsFuncCall
            || $expr instanceof Op\Expr\MethodCall
            || $expr instanceof Op\Expr\NullsafeMethodCall
            || $expr instanceof Op\Expr\StaticCall
        ) {
            if ($expr instanceof Op\Expr\MethodCall || $expr instanceof Op\Expr\NullsafeMethodCall) {
                if (
                    isset($expr->var)
                    && $expr->var instanceof Operand
                    && (
                        $expr->var === $operand
                        || $this->operandsReferToSameVariable($expr->var, $operand)
                    )
                ) {
                    return true;
                }
            }
            if (!property_exists($expr, 'args') || !\is_array($expr->args)) {
                return false;
            }
            foreach ($expr->args as $arg) {
                if (!($arg instanceof Operand)) {
                    continue;
                }
                if ($arg === $operand || $this->operandsReferToSameVariable($arg, $operand)) {
                    return true;
                }
                // Distinct dead temps: arg was written by the same ClassConstFetch/ConstFetch as $operand (#19439).
                if (
                    isset($arg->ops)
                    && \is_array($arg->ops)
                    && isset($operand->ops)
                    && \is_array($operand->ops)
                ) {
                    foreach ($arg->ops as $argWriteOp) {
                        if (
                            ($argWriteOp instanceof Op\Expr\ClassConstFetch
                                || $argWriteOp instanceof Op\Expr\ConstFetch)
                            && \in_array($argWriteOp, $operand->ops, true)
                        ) {
                            return true;
                        }
                    }
                }
                if (
                    isset($arg->ops)
                    && \is_array($arg->ops)
                    && (
                        ($operandWriter = $this->soleWriteExprForOperand($operand)) instanceof Op\Expr
                    )
                    && \in_array($operandWriter, $arg->ops, true)
                ) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
    protected function findPropertyFetchForResult(Operand $result, Block $block): ?Op\Expr\PropertyFetch
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\PropertyFetch && $child->result === $result) {
                return $child;
            }
        }
        // php-cfg may allocate a distinct FuncCall arg temp whose sole writer is the prop
        // fetch (`f((new stdClass)->x)` — arg !== PropertyFetch->result) (#29522).
        $writer = $result->ops[0] ?? null;
        if ($writer instanceof Op\Expr\PropertyFetch) {
            return $writer;
        }

        return null;
    }

    /**
     * Property fetch for `[&$obj->prop]` array-literal refs — operand may be the fetch expr (#17353).
     */
    private function resolvePropertyFetchForArrayLiteralRef(Operand $valueExpr, Block $block): ?Op\Expr\PropertyFetch
    {
        if ($valueExpr instanceof Op\Expr\PropertyFetch) {
            return $valueExpr;
        }
        $unwrapped = $this->unwrapOperandChain($valueExpr);
        if ($unwrapped instanceof Op\Expr\PropertyFetch) {
            return $unwrapped;
        }
        $producer = $this->findCfgProducerExprForOperand($valueExpr);
        if ($producer instanceof Op\Expr\PropertyFetch) {
            return $producer;
        }

        return $this->findPropertyFetchForResult($valueExpr, $block);
    }

    /**
     * php-cfg lowers short list `[$a, $b] = …` and `[$a, $b]` RHS via Op\Expr\Array_ (#1222).
     */
    protected function unwrapArrayLiteralExpr(Operand $operand): ?Op\Expr\Array_
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\Array_) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\Array_) {
            return $operand;
        }

        return null;
    }

    private function unsetTerminalUsesOperand(Op\Terminal\Unset_ $unset, Operand $operand): bool
    {
        foreach ($unset->exprs as $expr) {
            if ($expr === $operand) {
                return true;
            }
        }

        return false;
    }

    protected function unwrapArrayDimFetch(Operand $operand): ?Op\Expr\ArrayDimFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapPropertyFetch(Operand $operand): ?Op\Expr\PropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\PropertyFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapStaticPropertyFetch(Operand $operand): ?Op\Expr\StaticPropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\StaticPropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\StaticPropertyFetch) {
            return $operand;
        }

        return null;
    }

    /**
     * php-cfg may emit StaticPropertyFetch + Terminal_Unset on the fetch result temp (#2256).
     */
    protected function findStaticPropertyFetchForUnset(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        return $this->findStaticPropertyFetchForLvalue($expr, $block);
    }

    /**
     * php-cfg may split StaticPropertyFetch and Assign across statements (#6769).
     */
    protected function findStaticPropertyFetchForAssign(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        return $this->findStaticPropertyFetchForLvalue($expr, $block);
    }

    /**
     * @return Op\Expr\StaticPropertyFetch|null
     */
    protected function findStaticPropertyFetchForLvalue(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        $direct = $this->unwrapStaticPropertyFetch($expr);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$expr];
        if ($expr instanceof Operand\Variable) {
            $candidates[] = $expr->name;
        }
        $target = $expr;
        while ($target instanceof Temporary) {
            $candidates[] = $target;
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\StaticPropertyFetch) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if ($child->result === $candidate) {
                    return $child;
                }
            }
        }

        return null;
    }

    protected function requireOperandSlot(?int $slot, string $context): int
    {
        if (null === $slot) {
            $this->throwCompileLogic('Missing operand slot for '.$context);
        }

        return $slot;
    }

    /**
     * Compile a class-name operand, rewriting eval-donor `self` to the enclosing FQCN (#31912).
     *
     * php-src zend_eval_string compiles with the caller's scope so php-cfg MagicStringResolver
     * would have rewritten `self` during a method compile. Eval is a separate translation unit.
     */
    protected function compileClassNameOperand(Operand $class, Block $block): int
    {
        return $this->compileOperand($this->rewriteEvalDonorClassOperand($class), $block, true);
    }

    private function rewriteEvalDonorClassOperand(Operand $class): Operand
    {
        if (null === $this->evalClassScopeDisplay || '' === $this->evalClassScopeDisplay) {
            return $class;
        }
        $name = $this->staticNameFromOperand($class);
        if (null === $name) {
            return $class;
        }
        if ('self' === strtolower($name)) {
            return new Literal($this->evalClassScopeDisplay);
        }

        return $class;
    }

    /**
     * Static property name operand (#23606, zend_compile.c / zend_object_handlers.c).
     *
     * php-cfg already distinguishes forms:
     * - `Class::$prop` → Literal name (VarLikeIdentifier) — always the property name string
     * - `Class::$$var` / `Class::${expr}` → Variable / expression — runtime name
     *
     * Do not rewrite undeclared Literals into local-variable lookups: that truncated
     * Error messages to `Class::$` and made undeclared access look like an empty name.
     */
    protected function compileStaticPropertyNameSlot(Operand $name, Operand $class, Block $block): int
    {
        return $this->compileOperand($name, $block, true);
    }
}
