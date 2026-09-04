<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Op;

/**
 * Reject invalid write-context operands at compile time (#36403 / #36387).
 *
 * Expanded from {@see \PHPCompiler\Compiler}: $this/$GLOBALS, const/class-const,
 * array-literal, and `new` temporary write-context fatals (zend_compile.c).
 */
trait WriteContextRejects
{
    protected function isCallReturnWriteExpr(Op $op): bool
    {
        return $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\NullsafeMethodCall
            || $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall;
    }

    /**
     * Direct call-result lvalue only — dim/prop of a call return remain writable (#26436).
     */
    protected function findDirectCallReturnForWriteOperand(?Operand $operand, ?Block $block): ?Op\Expr
    {
        if (null === $operand || null === $block || null === $block->orig) {
            return null;
        }
        if (
            $operand instanceof Operand\Temporary
            && null !== $operand->original
            && $operand->original instanceof Op
            && $this->isCallReturnWriteExpr($operand->original)
        ) {
            return $operand->original;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr || !$this->isCallReturnWriteExpr($child)) {
                continue;
            }
            if ($child->result === $operand) {
                return $child;
            }
            if ($this->operandsReferToSameVariable($child->result, $operand)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Zend zend_compile.c: method/function call results are illegal write targets (#26436).
     *
     * @return never
     */
    protected function rejectCallReturnInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        $call = $this->findDirectCallReturnForWriteOperand($var, $block);
        if (null === $call) {
            return;
        }
        $site = $siteOp ?? $call;
        if (
            $call instanceof Op\Expr\FuncCall
            || $call instanceof Op\Expr\NsFuncCall
        ) {
            $this->throwWriteContextCompileFatal(
                "Can't use function return value in write context",
                $var,
                $block,
                $site,
            );
        }
        $this->throwWriteContextCompileFatal(
            "Can't use method return value in write context",
            $var,
            $block,
            $site,
        );
    }
    /**
     * Zend zend_compile.c: assignment to $this is a compile-time fatal (#4865).
     *
     * @return never
     */
    protected function rejectThisReassignment(?Operand $var): void
    {
        if (null === $var) {
            return;
        }
        if ('this' === $this->baseVariableName($var)) {
            $this->throwCompileError('Cannot re-assign $this');
        }
    }

    /**
     * Zend zend_compile.c: acquiring a reference to $GLOBALS is a compile-time fatal (#15627).
     *
     * @return never
     */
    protected function rejectGlobalsReferenceAcquisition(?Operand $expr): void
    {
        if (null === $expr) {
            return;
        }
        if ('GLOBALS' === $this->baseVariableName($expr)) {
            $this->throwCompileError('Cannot acquire reference to $GLOBALS');
        }
    }

    /**
     * Zend zend_compile.c zend_ensure_writable_variable(): bare $GLOBALS is not a write target (#32229).
     * Indexed $GLOBALS[$name] remains legal. Message matches php-src exactly.
     *
     * @return never
     */
    protected function rejectGlobalsWrite($var, ?Op $source = null, ?Block $block = null): void
    {
        if (!$var instanceof Operand) {
            return;
        }
        if (!$this->isBareGlobalsVariable($var, $block)) {
            return;
        }
        $detail = '$GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax';
        if (null !== $source) {
            $sourceFile = $source->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            $this->throwCompileError($detail, $sourceFile, $source->getLine());
        }
        $this->throwCompileError($detail);
    }

    /**
     * Zend zend_compile.c zend_compile_assign_dim(): `$GLOBALS[]` is never a legal write (#32253).
     * Indexed `$GLOBALS[$name]` remains legal; empty-dim append uses a distinct diagnostic from #32229.
     *
     * @return never
     */
    protected function rejectGlobalsAppend(Op\Expr\ArrayDimFetch $fetch, ?Block $block = null): void
    {
        if (!$this->isArrayAppendDim($fetch->dim)) {
            return;
        }
        $container = $fetch->var;
        if (!$container instanceof Operand) {
            return;
        }
        if (!$this->isBareGlobalsVariable($container, $block)) {
            return;
        }
        $detail = 'Cannot append to $GLOBALS';
        $sourceFile = $fetch->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $fetch->getLine());
    }

    /**
     * True for `$GLOBALS` itself, not `$GLOBALS[$name]` / `$GLOBALS->x` (#32229).
     */
    private function isBareGlobalsVariable(Operand $operand, ?Block $block = null): bool
    {
        if (null !== $this->unwrapArrayDimFetch($operand)) {
            return false;
        }
        if (null !== $this->unwrapPropertyFetch($operand)) {
            return false;
        }
        if (null !== $block && null !== $this->findArrayDimFetchForResult($operand, $block)) {
            return false;
        }

        return 'GLOBALS' === $this->baseVariableName($operand);
    }

    /**
     * Zend zend_compile.c: unset($this) is a compile-time fatal (#5436).
     *
     * @return never
     */
    protected function rejectThisUnset($expr): void
    {
        if (!$expr instanceof Operand) {
            return;
        }
        if ('this' === $this->unsetTargetVariableName($expr)) {
            $this->throwCompileError('Cannot unset $this');
        }
    }

    private function unsetTargetVariableName(Operand $expr): ?string
    {
        $name = $this->baseVariableName($expr);
        if (null !== $name) {
            return $name;
        }
        $var = $this->unwrapVariableOperand($expr);
        if (null !== $var && $var->name instanceof Literal && is_string($var->name->value)) {
            return $var->name->value;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function operandUsedInWriteContext(array $ops, int $startIndex, Operand $operand): bool
    {
        for ($j = $startIndex, $count = count($ops); $j < $count; ++$j) {
            $op = $ops[$j];
            if ($this->isDirectWriteUseOfOperand($op, $operand)) {
                return true;
            }
            if ($op instanceof Op\Expr\NullsafePropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            // Chained write: $a?->b->x = / ++ — PropertyFetch sits between nullsafe and assign (#25560).
            if ($op instanceof Op\Expr\PropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\ArrayDimFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\BinaryOp\Coalesce
                && $this->operandsChainEqual($op->left, $operand)
                && $j + 1 < $count
                && $ops[$j + 1] instanceof Op\Expr\Assign
                && $this->isCoalesceAssignTail($ops[$j + 1], $op)
                && $this->operandsChainEqual($ops[$j + 1]->var, $op->left)) {
                return true;
            }
        }

        return false;
    }

    private function isDirectWriteUseOfOperand(Op $op, Operand $operand): bool
    {
        if ($op instanceof Op\Expr\Assign && $this->operandsChainEqual($op->var, $operand)) {
            return true;
        }
        if ($op instanceof Op\Expr\AssignRef && $this->operandsChainEqual($op->var, $operand)) {
            return true;
        }
        if ($op instanceof Op\Terminal\Unset_) {
            foreach ($op->exprs as $var) {
                if ($this->operandsChainEqual($var, $operand)) {
                    return true;
                }
                $target = $var;
                while ($target instanceof Temporary) {
                    if ($this->operandsChainEqual($target, $operand)) {
                        return true;
                    }
                    if (null === $target->original) {
                        break;
                    }
                    $target = $target->original;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\PostInc
            || $op instanceof Op\Expr\PreInc
            || $op instanceof Op\Expr\PostDec
            || $op instanceof Op\Expr\PreDec) {
            $write = $op->write ?? $op->read;

            return $this->operandsChainEqual($write, $operand);
        }

        return false;
    }


    /**
     * File-scope `const` names registered during this compile unit (#6935).
     */
    protected function operandIsCompileTimeGlobalConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null === $name) {
                return false;
            }

            return isset($this->compileTimeGlobalConsts[strtolower($name)]);
        }
        if (null === $block || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) !== $root) {
                continue;
            }
            $name = $this->staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            if (isset($this->compileTimeGlobalConsts[strtolower($name)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class `const` names registered during this compile unit (#5409).
     */
    protected function operandIsCompileTimeClassConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $this->compileTimeClassConstFetchRegistered($root, $block);
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) !== $root) {
                continue;
            }
            if ($this->compileTimeClassConstFetchRegistered($child, $block)) {
                return true;
            }
        }

        return false;
    }

    protected function compileTimeClassConstFetchRegistered(
        Op\Expr\ClassConstFetch $fetch,
        Block $block,
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName || 'class' === strtolower($constName)) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return false;
        }

        return isset($this->compileTimeClassConsts[$lcClass][ClassConstName::key($constName)])
            && ClassConstName::matchesDeclared(
                $constName,
                $this->compileTimeClassConstNames[$lcClass][ClassConstName::key($constName)] ?? null
            );
    }

    protected function operandIsCompileTimeConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        return $this->operandIsCompileTimeGlobalConstFetch($operand, $block)
            || $this->operandIsCompileTimeClassConstFetch($operand, $block);
    }

    /**
     * Any ConstFetch / ClassConstFetch (registered or not).
     *
     * Zend rejects dim/prop write and assign-by-ref on constant fetches as
     * "temporary expression in write context" even when the name is undefined
     * or only exists via runtime define() (#5409, #26488). Registration in
     * compileTimeGlobalConsts must not gate this — #17676 correctly stopped
     * seeding define() array values for folding, which regressed write-context.
     */
    protected function operandIsConstOrClassConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ConstFetch || $root instanceof Op\Expr\ClassConstFetch) {
            return true;
        }
        if (null === $block || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ConstFetch && !$child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) === $root) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: mutating a const/class-const array is a compile-time fatal (#6935, #5409, #26488).
     */
    protected function lvalueContainsGlobalConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                /** @var Op\Expr\PropertyFetch $propFetch */
                $propFetch = $operand->original;
                if ($this->operandIsConstOrClassConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                /** @var Op\Expr\ArrayDimFetch $dimFetch */
                $dimFetch = $operand->original;
                if ($this->operandIsConstOrClassConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }

            return $this->lvalueContainsGlobalConstFetch($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandIsConstOrClassConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandIsConstOrClassConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }
        }

        return $this->operandIsConstOrClassConstFetch($operand, $block);
    }

    /**
     * Resolve Zend-shaped file/line for temporary write-context fatals (#29769 / #27718).
     *
     * @return array{0: string, 1: int}
     */
    protected function resolveWriteContextFatalSite(?Operand $var, ?Block $block, ?Op $siteOp = null): array
    {
        $file = '';
        $line = 0;
        if (null !== $siteOp) {
            $file = (string) ($siteOp->getFile() ?? '');
            $line = (int) $siteOp->getLine();
        }
        if (
            ('' === $file || $line <= 0)
            && $var instanceof Operand\Temporary
            && $var->original instanceof Op
        ) {
            if ('' === $file) {
                $file = (string) ($var->original->getFile() ?? '');
            }
            if ($line <= 0) {
                $line = (int) $var->original->getLine();
            }
        }
        if (('' === $file || $line <= 0) && null !== $block && null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op) {
                    continue;
                }
                $matches = false;
                if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                    $matches = null !== $var && (
                        $child->var === $var
                        || $this->operandsReferToSameVariable($child->var, $var)
                    );
                } elseif ($child instanceof Op\Expr\ArrayDimFetch || $child instanceof Op\Expr\PropertyFetch) {
                    $matches = null !== $var && (
                        $child->result === $var
                        || $this->operandsReferToSameVariable($child->result, $var)
                    );
                }
                if (!$matches) {
                    continue;
                }
                if ('' === $file) {
                    $file = (string) ($child->getFile() ?? '');
                }
                if ($line <= 0) {
                    $line = (int) $child->getLine();
                }
                if ('' !== $file && $line > 0) {
                    break;
                }
            }
        }
        if ('' === $file && null !== $block) {
            $file = $block->scriptPath();
        }
        if ('' === $file) {
            $file = $this->debugLastPhaseInputFile ?? 'unknown';
        }
        if ('' === $file) {
            $file = 'unknown';
        }

        return [$file, max(1, $line)];
    }

    /**
     * Zend-shaped temporary write-context compile fatal (not parseAndCompile wrapper) (#29769).
     *
     * @return never
     */
    protected function throwWriteContextCompileFatal(
        string $message,
        ?Operand $var = null,
        ?Block $block = null,
        ?Op $siteOp = null,
    ): void {
        [$file, $line] = $this->resolveWriteContextFatalSite($var, $block, $siteOp);
        $this->throwCompileError($message, $file, $line);
    }

    /**
     * @return never
     */
    protected function rejectGlobalConstInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        if (!$this->lvalueContainsGlobalConstFetch($var, $block)) {
            return;
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $siteOp,
        );
    }

    /**
     * True when $operand is an inline Op\Expr\Array_ result (php-cfg may omit ->original).
     */
    protected function operandIsArrayLiteral(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        if (null !== $this->unwrapArrayLiteralExpr($operand)) {
            return true;
        }
        if (null === $block) {
            return false;
        }

        return null !== $this->findArrayExprForResult($operand, $block);
    }

    /**
     * Zend zend_compile.c: dim/append/unset on an array literal is a temporary write (#29247).
     *
     * Function-return dims remain writable (f()[0] = …); only inline Expr_Array bases are rejected.
     */
    protected function lvalueContainsArrayLiteral(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                $propFetch = $operand->original;
                if ($this->operandIsArrayLiteral($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                $dimFetch = $operand->original;
                if ($this->operandIsArrayLiteral($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($dimFetch->var, $block);
            }

            return $this->lvalueContainsArrayLiteral($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandIsArrayLiteral($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandIsArrayLiteral($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($dimFetch->var, $block);
            }
        }

        return $this->operandIsArrayLiteral($operand, $block);
    }

    /**
     * @return never
     */
    protected function rejectArrayLiteralInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        if (!$this->lvalueContainsArrayLiteral($var, $block)) {
            return;
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $siteOp,
        );
    }

    /**
     * Shared write-context guards for Assign / unset / FETCH_*_W (incl. by-ref call args) (#29522).
     *
     * Function-return dims remain writable (f(g()[0]) when g returns by value) — only temporary
     * array literals / new / const / bare call returns are rejected.
     */
    protected function rejectTemporaryExpressionInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        $this->rejectNewExprInWriteContext($var, $block, null, null, $siteOp);
        $this->rejectArrayLiteralInWriteContext($var, $block, $siteOp);
        $this->rejectGlobalConstInWriteContext($var, $block, $siteOp);
        $this->rejectCallReturnInWriteContext($var, $block, $siteOp);
    }

    /**
     * Zend zend_compile.c: SEND_REF of temporary lit-dim / new-prop / const is illegal (#29522).
     *
     * Function-return dims remain allowed (f(g()[0])); do not call rejectCallReturnInWriteContext.
     */
    protected function rejectTemporaryByRefCallArg(?Operand $arg, ?Block $block = null, ?Op $siteOp = null): void
    {
        $this->rejectNewExprInWriteContext($arg, $block, null, null, $siteOp);
        $this->rejectArrayLiteralInWriteContext($arg, $block, $siteOp);
        $this->rejectGlobalConstInWriteContext($arg, $block, $siteOp);
    }

    /**
     * Zend zend_compile.c: assigning to a property/offset of a `new` temporary is illegal (#6691).
     */
    protected function lvalueContainsNewExpr(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                $propFetch = $operand->original;
                if ($this->operandDerivesFromNew($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                $dimFetch = $operand->original;
                if ($this->operandDerivesFromNew($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($dimFetch->var, $block);
            }

            return $this->lvalueContainsNewExpr($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandDerivesFromNew($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandDerivesFromNew($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($dimFetch->var, $block);
            }
        }

        return $this->operandDerivesFromNew($operand, $block);
    }

    /**
     * @return never
     */
    protected function rejectNewExprInWriteContext(
        ?Operand $var,
        ?Block $block = null,
        ?Operand $assignExpr = null,
        ?Op $assignOp = null,
        ?Op $siteOp = null,
    ): void {
        if (!$this->lvalueContainsNewExpr($var, $block)) {
            return;
        }
        $site = $siteOp ?? $assignOp;
        if (null !== $assignExpr && null !== $block && null !== $this->findArrayDimFetchForResult($assignExpr, $block)) {
            if ($assignOp instanceof Op\Expr\Assign) {
                $this->throwListDestructNonWritableWriteFatal($assignOp);
            }
            $this->throwWriteContextCompileFatal(
                'Assignments can only happen to writable values',
                $var,
                $block,
                $site,
            );
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $site,
        );
    }
}
