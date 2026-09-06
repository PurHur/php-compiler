<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Compile-time constant folding for defaults / const fetches (#36230 step 1).
 *
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 */
trait CompileTimeFold
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

    /**
     * Fold compile-time scalar casts, including (string) NAN/INF (#10143, zend_operators.c).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeCastDefault(
        Op\Expr\Cast $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $operand = $this->tryFoldCompileTimeOperandDefault(
            $expr->expr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $operand) {
            return null;
        }
        $castOpcode = $this->getOpCodeTypeFromCastOp($expr);
        if (OpCode::TYPE_CAST_OBJECT === $castOpcode) {
            return $this->tryFoldCompileTimeObjectCastOperand($operand);
        }
        $targetType = match ($castOpcode) {
            OpCode::TYPE_CAST_STRING => Variable::TYPE_STRING,
            OpCode::TYPE_CAST_INT => Variable::TYPE_INTEGER,
            OpCode::TYPE_CAST_FLOAT => Variable::TYPE_FLOAT,
            OpCode::TYPE_CAST_BOOL => Variable::TYPE_BOOLEAN,
            default => null,
        };
        if (null === $targetType) {
            return null;
        }
        $result = new Variable();
        try {
            $result->castFrom($targetType, $operand);
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold (object) array-literal casts for define() prescan / const folding (#17676, zend_operators.c cast_object).
     */
    protected function tryFoldCompileTimeObjectCastOperand(Variable $operand): ?Variable
    {
        if ($operand->is(Variable::TYPE_OBJECT)) {
            $copy = new Variable();
            $copy->copyFrom($operand);

            return ClassConstMaterializer::detachConstantValue($copy);
        }
        $class = new ClassEntry('stdClass');
        $class->allowsDynamicProperties = true;
        $object = new ObjectEntry($class);
        $object->constructed = true;
        if ($operand->is(Variable::TYPE_ARRAY)) {
            foreach ($operand->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $propName = $keyVar->is(Variable::TYPE_INTEGER)
                    ? (string) $keyVar->toInt()
                    : $keyVar->toString();
                $object->allocateProperty($propName)->copyFrom(
                    ClassConstMaterializer::detachConstantValue($valueVar)
                );
            }
        } elseif (!$operand->is(Variable::TYPE_NULL)) {
            if (!$operand->is(
                Variable::TYPE_BOOLEAN,
                Variable::TYPE_INTEGER,
                Variable::TYPE_FLOAT,
                Variable::TYPE_STRING
            )) {
                return null;
            }
            $object->allocateProperty('scalar')->copyFrom(
                ClassConstMaterializer::detachConstantValue($operand)
            );
        }
        $result = new Variable(Variable::TYPE_OBJECT);
        $result->object($object);

        return ClassConstMaterializer::detachConstantValue($result);
    }

    /**
     * define('NAME', (object)[...]) — prescan must not register the inner array (#17676).
     */
    protected function cfgArrayIsObjectCastSourceForOperand(
        Operand $arrayResult,
        Operand $valueRoot,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Cast\Object_) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->result, $valueRoot)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($child->expr, $arrayResult)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold unary const-expr operators in parameter/property defaults (#5166, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeUnaryExprDefault(
        Op\Expr $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (!$expr instanceof Op\Expr\BitwiseNot && !$expr instanceof Op\Expr\BooleanNot) {
            return null;
        }
        $opCode = $this->getOpCodeTypeFromUnaryOp($expr);
        if (!ClassConstExpr::isSupportedOpcode($opCode)) {
            return null;
        }
        $operand = $this->tryFoldCompileTimeOperandDefault(
            $expr->expr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $operand) {
            return null;
        }
        $result = new Variable();
        try {
            $result->unaryOp($opCode, $operand);
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold {@code -CONST}/{@code +CONST} when the operand is a ConstFetch prelude (#23997).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeUnaryMinusPlusDefault(
        Op\Expr\UnaryMinus|Op\Expr\UnaryPlus $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $operand = $this->tryFoldCompileTimeOperandDefault(
            $expr->expr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $operand) {
            return null;
        }
        $opCode = $expr instanceof Op\Expr\UnaryMinus
            ? OpCode::TYPE_UNARY_MINUS
            : OpCode::TYPE_UNARY_PLUS;
        $result = new Variable();
        try {
            $result->unaryOp($opCode, $operand);
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold {@code left ?? right} in const-expr defaults (#23997, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeCoalesceDefault(
        Op\Expr\BinaryOp\Coalesce $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $left = $this->tryFoldCompileTimeOperandDefault(
            $expr->left,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $left) {
            return null;
        }
        if (!$left->is(Variable::TYPE_NULL)) {
            $kept = new Variable();
            $kept->copyFrom($left);

            return $kept;
        }
        $right = $this->tryFoldCompileTimeOperandDefault(
            $expr->right,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $right) {
            return null;
        }
        $result = new Variable();
        $result->copyFrom($right);

        return $result;
    }

    /**
     * Fold binary const-expr operators in parameter/property defaults (#5166, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeBinaryExprDefault(
        Op\Expr\BinaryOp $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            return $this->tryFoldCompileTimeCoalesceDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        $opCode = $this->getOpCodeTypeFromBinaryOp($expr);
        if (!ClassConstExpr::isSupportedOpcode($opCode)) {
            return null;
        }
        $left = $this->tryFoldCompileTimeOperandDefault(
            $expr->left,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        $right = $this->tryFoldCompileTimeOperandDefault(
            $expr->right,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $left || null === $right) {
            return null;
        }
        if (OpCode::TYPE_CONCAT === $opCode) {
            $result = new Variable(Variable::TYPE_STRING);
            $result->string($left->toString().$right->toString());

            return $result;
        }
        $result = new Variable();
        try {
            if (\in_array($opCode, [
                OpCode::TYPE_PLUS,
                OpCode::TYPE_MINUS,
                OpCode::TYPE_MUL,
                OpCode::TYPE_DIV,
                OpCode::TYPE_MODULO,
                OpCode::TYPE_POW,
            ], true)) {
                $result->numericOp($opCode, $left, $right);
            } elseif (\in_array($opCode, [
                OpCode::TYPE_SMALLER,
                OpCode::TYPE_GREATER,
                OpCode::TYPE_SMALLER_OR_EQUAL,
                OpCode::TYPE_GREATER_OR_EQUAL,
            ], true)) {
                $result->compareOp($opCode, $left, $right);
            } elseif (OpCode::TYPE_SPACESHIP === $opCode) {
                // Class/file const `<=>` — zend_const_expr_to_zval (#24928).
                $result->spaceshipOp($left, $right);
            } elseif (OpCode::TYPE_IDENTICAL === $opCode) {
                $result->bool($left->identicalTo($right));
            } elseif (OpCode::TYPE_NOT_IDENTICAL === $opCode) {
                $result->bool(!$left->identicalTo($right));
            } elseif (OpCode::TYPE_EQUAL === $opCode) {
                $result->bool($left->equals($right));
            } elseif (OpCode::TYPE_NOT_EQUAL === $opCode) {
                $result->bool(!$left->equals($right));
            } elseif (OpCode::TYPE_LOGICAL_XOR === $opCode) {
                $result->bool($left->toBool() !== $right->toBool());
            } else {
                $result->bitwiseOp($opCode, $left, $right);
            }
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold {@code E::Case->name}/{@code ->value} in parameter/property defaults (#7399, zend_compile.c).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldEnumCasePropertyFetchDefault(
        Op\Expr\PropertyFetch $expr,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $propName = $this->staticNameFromOperand($expr->name);
        if (null === $propName) {
            return null;
        }
        $receiver = $this->tryFoldCompileTimeOperandDefault(
            $expr->var,
            $block,
            $defaultBlockChildren,
            true
        );
        if (null === $receiver) {
            return null;
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            return $receiver->toEnumCase()->fetchProperty($propName);
        }
        if (Variable::TYPE_OBJECT === $receiver->type && EnumCaseSupport::isEnumCase($receiver->toObject())) {
            return EnumCaseSupport::getProperty($receiver->toObject(), $propName);
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeOperandDefault(
        ?Operand $operand,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (null === $operand) {
            return null;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($operand);
        if (null !== $vm) {
            return $vm;
        }
        foreach ($defaultBlockChildren as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!property_exists($child, 'result') || !$this->operandsReferToSameVariable($child->result, $operand)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault(
                $child,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null !== $vm) {
                return $vm;
            }
        }

        return null;
    }

    protected function tryFoldUnaryLiteralDefault(Op\Expr\UnaryMinus|Op\Expr\UnaryPlus $expr): ?Variable
    {
        $vm = $this->vmVariableFromCfgLiteralOperand($expr->expr);
        if (null === $vm) {
            return null;
        }
        if ($vm->is(Variable::TYPE_INTEGER)) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $n = $vm->toInt();
            $value->int($expr instanceof Op\Expr\UnaryMinus ? -$n : $n);

            return $value;
        }
        if ($vm->is(Variable::TYPE_FLOAT)) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $n = $vm->toFloat();
            $value->float($expr instanceof Op\Expr\UnaryMinus ? -$n : $n);

            return $value;
        }

        return null;
    }

}
