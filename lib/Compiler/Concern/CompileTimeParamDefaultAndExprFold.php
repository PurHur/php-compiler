<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\VM\Variable;

/**
 * Compile-time property/param default + ternary/expr/magic/array-dim folding (#36387).
 *
 * Extracted from {@see CompileTimeFold} so gen-0 split-TU can hollow a smaller Concern TU.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_compile.c ZEND_AST_CONST / ZEND_AST_MAGIC_CONST / default
 * folding — move-only, no new C ABI.
 */
trait CompileTimeParamDefaultAndExprFold
{
    protected function tryFoldPropertyDefaultSlot(Op\Stmt\Property $prop, Block $block): ?int
    {
        if (null === $prop->defaultVar) {
            return null;
        }
        $propertyType = $prop->declaredType ?? new Op\Type\Literal('mixed');
        $pseudo = new Op\Expr\Param(
            new Operand\Literal(''),
            new Op\Type\Mixed_(),
            false,
            false,
            $prop->defaultVar,
            $prop->defaultBlock
        );

        return $this->tryFoldParamDefaultSlot($pseudo, $block);
    }

    protected function tryFoldParamDefaultSlot(Op\Expr\Param $param, Block $block): ?int
    {
        if (null === $param->defaultVar) {
            return null;
        }
        if ($param->defaultVar instanceof Operand\NullOperand) {
            return $this->registerNullConstantSlot($block, $param->defaultVar);
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($param->defaultVar);
        if (null !== $vm) {
            return $block->registerConstant($param->defaultVar, $vm);
        }
        if (null === $param->defaultBlock || [] === $param->defaultBlock->children) {
            return null;
        }
        $children = $param->defaultBlock->children;
        if ([] === $children) {
            return null;
        }
        foreach ($children as $child) {
            if (!$child instanceof Op\Stmt\JumpIf) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeTernaryDefault(
                $child,
                $param->defaultVar,
                $block,
                $children,
                true
            );
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        $expr = $children[\count($children) - 1];
        if (!$expr instanceof Op\Expr) {
            return null;
        }
        if ($expr instanceof Op\Expr\ConstFetch) {
            $vm = $this->tryFoldGlobalConstFetch($expr);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            $vm = $this->tryFoldClassConstFetchDefault($expr, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\Array_) {
            $vm = $this->tryBuildCompileTimeArrayFromExpr($expr, $block, $children);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            $vm = $this->tryFoldArrayDimFetchCompileTimeDefault($expr, $block, $children, true);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            $vm = $this->tryFoldUnaryLiteralDefault($expr);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        $vm = $this->tryFoldCompileTimeExprDefault($expr, $block, $children, true);
        if (null !== $vm) {
            return $block->registerConstant($param->defaultVar, $vm);
        }

        return null;
    }

    /**
     * Fold php-cfg ?: lowering (JumpIf + arm assigns) in param/static defaults (#12026).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeTernaryDefault(
        Op\Stmt\JumpIf $jumpIf,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        bool $materializeEnumCase = false
    ): ?Variable {
        $ifMerge = $this->branchJumpMergeTarget($jumpIf->if);
        $elseMerge = $this->branchJumpMergeTarget($jumpIf->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return null;
        }
        $condVm = $this->tryFoldCompileTimeOperandDefault(
            $jumpIf->cond,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $condVm) {
            return null;
        }
        $ifVm = $this->foldBranchCfgResultValue(
            $jumpIf->if,
            $result,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        $elseVm = $this->foldBranchCfgResultValue(
            $jumpIf->else,
            $result,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $ifVm || null === $elseVm) {
            return null;
        }

        $chosen = $condVm->toBool() ? $ifVm : $elseVm;
        $folded = new Variable();
        $folded->copyFrom($chosen);

        return $folded;
    }

    /**
     * Fold a ternary / logical-short-circuit arm that assigns into the merge result (#17229).
     *
     * @param list<Op> $defaultBlockChildren
     */
    private function foldBranchCfgResultValue(
        CfgBlock $branchCfg,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        bool $materializeEnumCase
    ): ?Variable {
        $branchChildren = array_merge($defaultBlockChildren, $branchCfg->children);
        foreach ($branchCfg->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $branchChildren,
                    $materializeEnumCase
                );
            }
            if (
                property_exists($child, 'result')
                && $this->operandsReferToSameVariable($child->result, $result)
            ) {
                return $this->tryFoldCompileTimeExprDefault(
                    $child,
                    $block,
                    $branchChildren,
                    $materializeEnumCase
                );
            }
        }

        return null;
    }

    private function branchCfgAssignExprForResult(CfgBlock $branchCfg, Operand $result): ?Operand
    {
        $assignVar = $this->mergeBranchAssignVarOperand($branchCfg);
        if (null === $assignVar || !$this->operandsReferToSameVariable($assignVar, $result)) {
            return null;
        }
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $child->expr;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeExprDefault(
        Op\Expr $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if ($expr instanceof Op\Expr\ConstFetch) {
            return $this->tryFoldGlobalConstFetch($expr);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $this->tryFoldClassConstFetchDefault($expr, $block, $materializeEnumCase);
        }
        if ($expr instanceof Op\Expr\Array_) {
            return $this->tryBuildCompileTimeArrayFromExpr($expr, $block, $defaultBlockChildren);
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            $literal = $this->tryFoldUnaryLiteralDefault($expr);
            if (null !== $literal) {
                return $literal;
            }

            return $this->tryFoldCompileTimeUnaryMinusPlusDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\BitwiseNot || $expr instanceof Op\Expr\BooleanNot) {
            return $this->tryFoldCompileTimeUnaryExprDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            return $this->tryFoldCompileTimeCoalesceDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $this->tryFoldCompileTimeBinaryExprDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $this->tryFoldEnumCasePropertyFetchDefault($expr, $block, $defaultBlockChildren);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->tryFoldArrayDimFetchCompileTimeDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $this->tryFoldCompileTimeCastDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\MagicScriptConst) {
            return $this->tryFoldMagicScriptConst($expr, $block);
        }

        return null;
    }

    /**
     * Fold __DIR__ / __FILE__ / __LINE__ / __COMPILER_HALT_OFFSET__ in const-expr
     * (class const, property/param defaults) — Zend zend_compile.c ZEND_AST_MAGIC_CONST (#24929).
     */
    protected function tryFoldMagicScriptConst(Op\Expr\MagicScriptConst $expr, Block $block): ?Variable
    {
        if (Op\Expr\MagicScriptConst::KIND_LINE === $expr->kind) {
            $line = max(1, $expr->getLine());
            if (\PHPCompiler\ext\standard\VmEval::isEvalScriptPath($block->scriptPath())) {
                $line = \PHPCompiler\ext\standard\VmEval::unwrapEvalLine($line);
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($line);

            return $value;
        }
        if (Op\Expr\MagicScriptConst::KIND_HALT_OFFSET === $expr->kind) {
            $offset = $block->haltCompilerOffset ?? $this->haltCompilerOffset;
            if (null === $offset) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($offset);

            return $value;
        }
        $path = $block->scriptPath();
        if ('' === $path) {
            return null;
        }
        if (Op\Expr\MagicScriptConst::KIND_DIR === $expr->kind) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string(dirname($path));

            return $value;
        }
        if (Op\Expr\MagicScriptConst::KIND_FILE === $expr->kind) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string($path);

            return $value;
        }

        return null;
    }

    /**
     * Fold literal-array subscript in const-expr defaults (static/param/property, #12025).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldArrayDimFetchCompileTimeDefault(
        Op\Expr\ArrayDimFetch $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (null === $expr->dim) {
            return null;
        }
        $base = $this->tryFoldCompileTimeOperandDefault(
            $expr->var,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $base || !$base->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $dimVm = $this->tryFoldCompileTimeOperandDefault(
            $expr->dim,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $dimVm) {
            return null;
        }
        $table = $base->toArray();
        if (!$table->keyExists($dimVm)) {
            return null;
        }
        $elem = $table->findVariable($dimVm, false);
        if (null === $elem) {
            return null;
        }
        $value = new Variable();
        $value->copyFrom($elem->resolveIndirect());

        return $value;
    }

}
