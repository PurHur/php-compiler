<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCompiler\Block;
use PHPCompiler\BuiltinFunctionClassConstant;
use PHPCompiler\BuiltinTypeClassConstant;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\DateConstants;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmPhpCoreConstants;

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

    protected function registerNullConstantSlot(Block $block, Operand $operand): int
    {
        return $block->registerConstant($operand, new Variable(Variable::TYPE_NULL));
    }

    protected function tryFoldGlobalConstFetch(Op\Expr\ConstFetch $expr): ?Variable
    {
        $name = $this->staticNameFromOperand($expr->name);
        if (null === $name) {
            return null;
        }
        $vm = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($name);
        if (null !== $vm) {
            return $vm;
        }
        $lc = strtolower($name);
        if ('null' === $lc) {
            return new Variable(Variable::TYPE_NULL);
        }
        if ('true' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(true);

            return $v;
        }
        if ('false' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(false);

            return $v;
        }
        $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($name);
        if (null !== $errorInt) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($errorInt);

            return $v;
        }
        $lc = strtolower($name);
        if ('inf' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(INF);

            return $v;
        }
        if ('nan' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(NAN);

            return $v;
        }
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            $value = new Variable();
            $value->copyFrom($this->compileTimeGlobalConsts[$lc]);

            return $value;
        }
        $stdlibInt = \PHPCompiler\ext\standard\StdlibConstants::coreIntByName($lc);
        if (null !== $stdlibInt) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($stdlibInt);

            return $v;
        }
        // M_PI / M_E / … — same map as VM\Context::constantFetch (#27249).
        $stdlibFloat = \PHPCompiler\ext\standard\StdlibConstants::CORE_FLOAT_BY_NAME[$lc] ?? null;
        if (null !== $stdlibFloat) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float($stdlibFloat);

            return $v;
        }
        $dateStr = \PHPCompiler\ext\standard\DateConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $dateStr) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($dateStr);

            return $v;
        }
        $stdlibStr = \PHPCompiler\ext\standard\StdlibConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $stdlibStr) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($stdlibStr);

            return $v;
        }

        return null;
    }

    /**
     * Pre-register enum `case` singletons for class/global const folding (#15737).
     *
     * Class/interface/trait bodies are hoisted before enum DECLARE opcodes; prescan
     * mirrors {@see compileEnum} metadata so {@code E::A} folds when enum is later in source.
     *
     * @param list<Op> $ops
     */
    protected function prescanCompileTimeEnumCases(array $ops): void
    {
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $this->prescanEnumCaseConstants($child);
            }
        }
    }

    protected function prescanEnumCaseConstants(Op\Stmt\Enum_ $enum): void
    {
        $enumName = $this->staticNameFromOperand($enum->name);
        if (null === $enumName) {
            return;
        }
        $enumLc = strtolower(ltrim($enumName, '\\'));
        $displayName = ltrim($enumName, '\\');
        $backedTypeName = null;
        if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
            $backedTypeName = $enum->backedType->name;
        }
        if (!isset($this->compileTimeEnumBackedTypes[$enumLc])) {
            $this->compileTimeEnumBackedTypes[$enumLc] = $backedTypeName;
        }
        if (!isset($this->compileTimeEnumCaseConstNames[$enumLc])) {
            $this->compileTimeEnumCaseConstNames[$enumLc] = [];
        }
        if (!isset($this->runtimeEnumCaseConsts[$enumLc])) {
            $this->runtimeEnumCaseConsts[$enumLc] = [];
        }

        $prevClassLc = $this->compilingClassLc;
        $prevDisplay = $this->compilingClassDisplayName;
        $this->compilingClassLc = $enumLc;
        $this->compilingClassDisplayName = $displayName;

        foreach ($enum->stmts->children as $stmt) {
            if (!$stmt instanceof Op\Terminal\Const_ || !$this->cfgTerminalConstIsEnumCase($stmt)) {
                continue;
            }
            $caseName = $this->staticNameFromOperand($stmt->name);
            if (null === $caseName) {
                continue;
            }
            $lcCase = ClassConstName::key($caseName);
            if (isset($this->runtimeEnumCaseConsts[$enumLc][$lcCase])) {
                continue;
            }
            $backing = $this->vmVariableFromCfgLiteralOperand($stmt->value);
            if (null === $backing) {
                if (null !== $backedTypeName) {
                    continue;
                }
                $backing = new Variable(Variable::TYPE_NULL);
                $backing->null();
            }
            $this->runtimeEnumCaseConsts[$enumLc][$lcCase] = $this->compileTimeEnumCaseVar(
                $displayName,
                $caseName,
                $backing,
                $backedTypeName
            );
            $this->compileTimeEnumCaseConstNames[$enumLc][$lcCase] = true;
        }

        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevDisplay;
    }

    /**
     * Pre-register global `const` and literal define() for default-value folding (#6542).
     *
     * @param list<Op> $ops
     */
    protected function prescanCompileTimeGlobalConsts(array $ops, Block $block): void
    {
        foreach ($ops as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->prescanGlobalConstTerminal($child, $block);
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall) {
                $this->prescanDefineFuncCall($child, $block);
            }
        }
    }

    protected function prescanGlobalConstTerminal(Op\Terminal\Const_ $const, Block $block): void
    {
        $this->rejectReservedGlobalConstName($const);
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name) {
            return;
        }
        $valueSlot = $this->tryFoldGlobalConstValueSlot($const, $block);
        if (null === $valueSlot || !isset($block->constants[$valueSlot])) {
            return;
        }
        $this->storeCompileTimeGlobalConst($name, $block->constants[$valueSlot]);
    }

    protected function prescanDefineFuncCall(Op\Expr\FuncCall $expr, Block $block): void
    {
        $fnName = $this->staticNameFromOperand($expr->name);
        if (null === $fnName || 'define' !== strtolower($fnName)) {
            return;
        }
        if (count($expr->args) < 2 || count($expr->args) > 3) {
            return;
        }
        $constNameArg = $expr->args[0];
        $valueArg = $expr->args[1];
        if (!$constNameArg instanceof Operand\Literal) {
            return;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return;
        }
        // Runtime define('NAME', expr) must not seed compileTimeGlobalConsts (#17676).
        if (!$valueArg instanceof Operand\Literal) {
            return;
        }
        $constName = $constNameArg->value;
        if (!is_string($constName) || '' === $constName || str_contains($constName, '::')) {
            return;
        }
        if ($this->defineValueRequiresRuntimeEvaluation($valueArg, $block)) {
            return;
        }
        // define() inside a function/method registers when that function runs (zend_constants.c).
        // Seeding compileTimeGlobalConsts here would fold the name in {main} before the call (#32039).
        if (!$this->compileBlockIsFileScopeMain($block)) {
            return;
        }
        $vm = $this->tryFoldDefineValueOperand($valueArg, $block);
        if (null === $vm) {
            return;
        }
        $this->storeCompileTimeGlobalConst($constName, $vm);
    }

    /**
     * Fold define('NAME', expr) value operands for compile-time const registration (#5409).
     */
    protected function tryFoldDefineValueOperand(Operand $valueArg, Block $block): ?Variable
    {
        $vm = $this->vmVariableFromCfgLiteralOperand($valueArg);
        if (null !== $vm) {
            return $vm;
        }
        if (null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($valueArg);
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($child->result, $root)
            ) {
                if ($this->cfgArrayIsObjectCastSourceForOperand($child->result, $root, $block)) {
                    continue;
                }
                return $this->tryBuildCompileTimeArrayFromExpr($child);
            }
            if (!$child instanceof Op\Expr || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault($child, $block, [$child], true);
            if (null !== $vm) {
                return $vm;
            }
        }

        return null;
    }

    /** define('N', (object)[...]) and other runtime-only values must not prescan (#17676). */
    protected function defineValueRequiresRuntimeEvaluation(Operand $valueArg, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($valueArg);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            if ($child instanceof Op\Expr\Cast) {
                return true;
            }
        }

        return false;
    }

    protected function storeCompileTimeGlobalConst(string $name, Variable $value): void
    {
        $lc = strtolower($name);
        // File-scope `const true` is a compile fatal (#32228); define('true') must not
        // fold later ConstFetch of the special name (zend_get_special_const).
        if ('true' === $lc || 'false' === $lc || 'null' === $lc) {
            return;
        }
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            return;
        }
        $stored = new Variable();
        $stored->copyFrom($value);
        $this->compileTimeGlobalConsts[$lc] = $stored;
    }

    /**
     * File-level {main} (not function/method/closure bodies).
     *
     * Literal define() may fold ConstFetch only in this scope (#6542). Nested define()
     * still executes at run time (#32039, zend_builtin_functions.c).
     */
    private function compileBlockIsFileScopeMain(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return true;
        }

        return '{main}' === $func->name && null === $func->class;
    }

    protected function tryFoldClassConstFetchDefault(
        Op\Expr\ClassConstFetch $expr,
        Block $block,
        bool $materializeEnumCase = false
    ): ?Variable {
        $constName = $this->staticNameFromOperand($expr->name);
        if (null !== $constName && 'class' === strtolower($constName)) {
            $enumFqcn = $this->tryFoldEnumCaseClassPseudoConstFqcn($expr->class, $block);
            if (null !== $enumFqcn) {
                $value = new Variable(Variable::TYPE_STRING);
                $value->string($enumFqcn);

                return $value;
            }
            $builtinClass = $this->staticNameFromOperand($expr->class);
            if (null !== $builtinClass) {
                $builtinName = BuiltinTypeClassConstant::classNameForTypeOperand($builtinClass);
                if (null !== $builtinName) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($builtinName);

                    return $value;
                }
                $builtinFn = BuiltinFunctionClassConstant::functionNameForClassOperand($builtinClass);
                if (null !== $builtinFn) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($builtinFn);

                    return $value;
                }
                // self/parent/Named::class — compile-time string (zend_compile.c, #26629 / #3803).
                // static::class stays unfolded so LSB call sites keep the runtime opcode (#19614).
                $pseudoFqcn = $this->resolveCompileTimeClassPseudoConstFqcn($builtinClass, $block);
                if (null !== $pseudoFqcn) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($pseudoFqcn);

                    return $value;
                }
            }
        }
        $className = $this->staticNameFromOperand($expr->class);
        if (null === $constName || null === $className) {
            return null;
        }
        // static::CONST / static::class need the called class at runtime (LSB).
        // Folding via the declaring class is self::-equivalent and wrong (#19614, zend_execute.c).
        if ('static' === strtolower($className)) {
            return null;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return null;
        }
        if ($this->classCompileRegistry->isTrait($lcClass)) {
            return null;
        }
        $constKey = ClassConstName::key($constName);
        if (isset($this->compileTimeClassConsts[$lcClass][$constKey])) {
            // Class constants are case-sensitive — do not fold wrong casing (#25910, #25929).
            $declared = $this->compileTimeClassConstNames[$lcClass][$constKey] ?? null;
            if (!ClassConstName::matchesDeclared($constName, $declared)) {
                return null;
            }
            if (!$this->compileTimeClassConstFetchAllowed($lcClass, $constKey, $block)) {
                return null;
            }
            // Deprecated constants must fetch at runtime so E_USER_DEPRECATED fires (#6962).
            if (isset($this->compileTimeClassConstDeprecated[$lcClass][$constKey])) {
                return null;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$constKey];
            // Enum case fetches defer to runtime unless folding defaults/const-expr (#8767, #7399).
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey) && !$materializeEnumCase) {
                return null;
            }
            if ($this->compileTimeStoredValueIsEnumCaseBackingScalar($lcClass, $constKey, $stored)) {
                return $this->compileTimeEnumCaseVar(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            // Non-literal duplicate backing falls back to runtime ensureBackedEnumValuesUnique (#5773).
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (isset($this->runtimeEnumCaseConsts[$lcClass][$constKey])) {
            $stored = $this->runtimeEnumCaseConsts[$lcClass][$constKey];
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey) && !$materializeEnumCase) {
                return null;
            }
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }

        return $this->tryFoldExternalClassConstFetch($className, $constName);
    }

    private function isCompileTimeEnumCaseConstantMember(string $lcClass, string $lcConst): bool
    {
        if (isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return true;
        }
        if (!isset($this->runtimeEnumCaseConsts[$lcClass][$lcConst])) {
            return false;
        }
        $stored = $this->runtimeEnumCaseConsts[$lcClass][$lcConst];

        return Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject()));
    }

    /**
     * Fold enum `case` fetches to enum case objects — never expose backing scalars (#5933, #5858).
     */
    private function materializeCompileTimeEnumCaseConstant(
        string $enumName,
        string $caseName,
        Variable $stored,
        ?string $backedType
    ): Variable {
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (Variable::TYPE_ENUM_CASE === $stored->type) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        $backing = new Variable();
        $backing->copyFrom($stored);

        return $this->compileTimeEnumCaseVar($enumName, $caseName, $backing, $backedType);
    }

    /**
     * Fold {@code EnumCase::class} to the enum type FQCN (Zend zend_compile.c; #5662).
     */
    protected function tryFoldEnumCaseClassPseudoConstFqcn(Operand $classOperand, Block $block): ?string
    {
        if ($classOperand instanceof Op\Expr\ClassConstFetch) {
            $inner = $this->tryFoldClassConstFetchDefault($classOperand, $block, true);
            if (null !== $inner) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($inner);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }
            $className = $this->staticNameFromOperand($classOperand->class);
            $caseName = $this->staticNameFromOperand($classOperand->name);
            if (null !== $className && null !== $caseName) {
                $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                if (null !== $lcClass
                    && $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($caseName))
                ) {
                    return ltrim($className, '\\');
                }
            }

            return null;
        }
        if (!$classOperand instanceof Operand\Variable && !$classOperand instanceof Temporary) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ClassConstFetch
                || !$this->operandsReferToSameVariable($child->result, $classOperand)
            ) {
                continue;
            }
            $className = $this->staticNameFromOperand($child->class);
            $caseName = $this->staticNameFromOperand($child->name);
            if (null === $className || null === $caseName) {
                continue;
            }
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            $lcConst = ClassConstName::key($caseName);
            if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst)) {
                continue;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$lcConst]
                ?? $this->runtimeEnumCaseConsts[$lcClass][$lcConst]
                ?? null;
            if (null !== $stored) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($stored);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }

            return ltrim($className, '\\');
        }

        return null;
    }

    protected function enumFqcnFromEnumCaseVariable(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            return $var->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $var->type && EnumCaseSupport::isEnumCase($var->toObject())) {
            return $var->toObject()->class->name;
        }

        return null;
    }

    protected function tryFoldExternalClassConstFetch(string $className, string $constName): ?Variable
    {
        $lcClass = strtolower(ltrim($className, '\\'));
        // Attribute::* from compiler profile — never host \Attribute (#20727).
        // Leave Attribute::class to the ::class / native paths (not a TARGET_* bit).
        if ('attribute' === $lcClass && 'class' !== strtolower($constName)) {
            $folded = AttributeSupport::builtinConstValue(strtolower($constName));
            if (null === $folded) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($folded);

            return $value;
        }
        if ('phpcfg\\func' === $lcClass) {
            $flags = [
                'FLAG_PUBLIC' => \PHPCfg\Func::FLAG_PUBLIC,
                'FLAG_PROTECTED' => \PHPCfg\Func::FLAG_PROTECTED,
                'FLAG_PRIVATE' => \PHPCfg\Func::FLAG_PRIVATE,
                'FLAG_STATIC' => \PHPCfg\Func::FLAG_STATIC,
                'FLAG_ABSTRACT' => \PHPCfg\Func::FLAG_ABSTRACT,
                'FLAG_FINAL' => \PHPCfg\Func::FLAG_FINAL,
                'FLAG_RETURNS_REF' => \PHPCfg\Func::FLAG_RETURNS_REF,
                'FLAG_CLOSURE' => \PHPCfg\Func::FLAG_CLOSURE,
            ];
            $lcConst = strtoupper($constName);
            if (!isset($flags[$lcConst])) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($flags[$lcConst]);

            return $value;
        }

        return $this->tryFoldNativePhpClassConstFetch(ltrim($className, '\\'), $constName);
    }

    /**
     * Fold class constants from already-loaded native PHP classes (bootstrap spine; #6221).
     */
    protected function tryFoldNativePhpClassConstFetch(string $className, string $constName): ?Variable
    {
        // ::class on bootstrap Internal handlers may not be loaded yet (#1492 spine compile).
        $autoload = 'class' === strtolower($constName);
        if (!class_exists($className, $autoload)) {
            return null;
        }
        // Host PHP may still expose the constant while our PROFILE marks it #[\Deprecated]
        // (e.g. DATE path DateTime::RFC7231 under PROFILE=8.5 on a Zend 8.2 host). Refuse
        // fold so VM/JIT emit E_USER_DEPRECATED at fetch (#28134).
        if ($this->vmClassConstFetchIsDeprecated($className, $constName)) {
            return null;
        }
        try {
            $ref = new \ReflectionClassConstant($className, $constName);
        } catch (\ReflectionException) {
            return null;
        }
        $raw = $ref->getValue();
        if (\is_int($raw)) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($raw);

            return $value;
        }
        if (\is_bool($raw)) {
            $value = new Variable(Variable::TYPE_BOOLEAN);
            $value->bool($raw);

            return $value;
        }
        if (\is_float($raw)) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $value->float($raw);

            return $value;
        }
        if (\is_string($raw)) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string($raw);

            return $value;
        }

        return null;
    }

    /** True when VM ClassEntry marks the constant #[\Deprecated] for the active profile. */
    private function vmClassConstFetchIsDeprecated(string $className, string $constName): bool
    {
        if (null === $this->vmContext) {
            return false;
        }
        $lc = strtolower(ltrim($className, '\\'));
        $entry = $this->vmContext->classes[$lc] ?? null;
        if (null === $entry) {
            return false;
        }
        $key = ClassConstName::key($constName);
        $meta = $entry->constDeprecated[$key] ?? null;
        if (null === $meta) {
            return false;
        }

        return $meta->emitsRuntimeNotice();
    }

}
