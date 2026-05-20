<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\Variable;

class Compiler {

    protected ?SplObjectStorage $seen;
    protected ?SplObjectStorage $funcs;

    public function compile(Script $script): ?Block {
        $this->seen = new SplObjectStorage;

        $main = $this->compileCfgBlock($script->main->cfg);

        $this->seen = null;
        return $main;
    }

    public function compileFunc(string $name, CfgFunc $func): Func {
        $this->seen = new SplObjectStorage;

        $funcBlock = $this->compileCfgBlock($func->cfg, $func->params);
        $funcBlock->func = $func;
        $this->seen = null;
        return new Func\PHP($name, $funcBlock);
    }

    protected function compileCfgBlock(CfgBlock $block, array $params = []): Block {
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            $paramIdx = 0;
            foreach ($params as $param) {
                $new->addOpCode($this->compileParam($param, $new, $paramIdx++));                
            }
            $this->compileBlock($new);
        }
        return $this->seen[$block];
    }

    protected function compileBlock(Block $block) {
        $this->compileOps($block->orig->children, $block);
    }

    protected function compileOps(array $ops, Block $block): void {
        // First hoist functions and class definitions
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                    $block->addOpCode($this->compileFunction($child, $block));
                    break;
                case Op\Stmt\Class_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    $block->addOpCode($this->compileClassLike($child, $block));
                    break;
            }
        }
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                case Op\Stmt\Class_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    break;
                default:
                    if ($child instanceof Op\Expr\Isset_ && count($child->vars) > 1) {
                        $block = $this->compileIssetMulti($child, $block);
                    } elseif ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                        $block = $this->compileCoalesce($child, $block);
                    } else {
                        $this->compileOp($child, $block);
                    }
            }
        }
    }

    protected function compileClassLike(Op\Stmt\ClassLike $class, Block $block): OpCode {
        $type = 0;
        if ($class instanceof Op\Stmt\Class_) {
            $type = OpCode::TYPE_DECLARE_CLASS;
            assert(null === $class->extends);
            assert(empty($class->implements));
        } else {
            throw new \LogicException('Unsupported class type: ' . get_class($class));
        }
        $return = new OpCode(
            $type,
            $this->compileOperand($class->name, $block, true)
        );
        $return->block1 = $this->compileClassBody($class->stmts, $type);
        return $return;
    }

    protected function compileClassBody(CfgBlock $block, int $type): Block {
        $result = new Block($block);
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    if ($type !== OpCode::TYPE_DECLARE_CLASS) {
                        throw new \LogicException('Properties are only supported on classes for now');
                    }
                    if (!is_null($child->defaultBlock)) {
                        $this->compileOps($child->defaultBlock, $result);
                    }
                    $result->addOpCode(new OpCode(
                        OpCode::TYPE_DECLARE_PROPERTY,
                        $this->compileOperand($child->name, $result, true),
                        is_null($child->defaultVar) ? null : $this->compileOperand($child->defaultVar, $result, true),
                        $this->compileTypeConstrainedVariable($result, $child->type)
                    ));
                    break;
                default:
                    throw new \LogicException('Unsupported class body element: ' . get_class($child));
            }
        }
        return $result;
    }

    protected function compileTypeConstrainedVariable(Block $block, Type $type): int {
        $var = new Variable(Variable::TYPE_UNDEFINED);
        $operand = new Operand\Temporary;
        $operand->type = $type;
        $return = $block->registerConstant($operand, $var);
        $mappedType = Variable::mapFromType($type);
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            // Mixed
            return $return;
        } elseif ($mappedType === Variable::TYPE_OBJECT) {
            $var->classConstraint = $type->userType;
        }
        $var->typeConstraint = $mappedType;
        return $return;
    }


    protected function compileParam(Op\Expr\Param $param, Block $block, int $paramIdx): OpCode {
        assert(false === $param->byRef);
        assert(false === $param->variadic);
        $defaultConst = null;
        if (null !== $param->defaultVar) {
            $defaultConst = $this->compileOperand($param->defaultVar, $block, true);
        }
        return new OpCode(
            OpCode::TYPE_ARG_RECV,
            $this->compileOperand($param->result, $block, false),
            $paramIdx,
            $defaultConst
        );
    }

    protected function compileFunction(Op\Stmt\Function_ $function, Block $block): OpCode {
        $funcBlock = $this->compileCfgBlock($function->func->cfg, $function->func->params);
        $funcBlock->func = $function->func;
        $operand = new Operand\Literal($function->func->name);
        $operand->type = Type::string();
        $return = new OpCode(
            OpCode::TYPE_FUNCDEF,
            $this->compileOperand($operand, $block, true)
        );
        $return->block1 = $funcBlock;
        return $return;
    }

    protected function compileOp(Op $op, Block $block) {
        if ($op instanceof Op\Expr\ConcatList) {
            $total = count($op->list);
            assert($total >= 2);
            $pointer = 2;

            $return = $this->compileOperand($op->result, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_CONCAT,
                $return,
                $this->compileOperand($op->list[0], $block, true),
                $this->compileOperand($op->list[1], $block, true)
            ));
            while ($pointer < $total) {
                $right = $this->compileOperand($op->list[$pointer++], $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_CONCAT,
                    $return,
                    $return,
                    $right
                ));
            }
        } elseif ($op instanceof Op\Expr) {
            $block->addOpCode(...$this->compileExpr($op, $block));
        } elseif ($op instanceof Op\Stmt) {
            $this->compileStmt($op, $block);
        } elseif ($op instanceof Op\Terminal) {
            $block->addOpCode($this->compileTerminal($op, $block));
        } else {
            throw new \LogicException("Unknown Op Type: " . $op->getType());
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block) {
        if ($stmt instanceof Op\Stmt\Jump) {
            $op = new OpCode(OpCode::TYPE_JUMP);
            $op->block1 = $this->compileCfgBlock($stmt->target);
            $op->block1->parents[] = $block;
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\JumpIf) {
            $op = new OpCode(OpCode::TYPE_JUMPIF, $this->compileOperand($stmt->cond, $block, true));
            $op->block1 = $this->compileCfgBlock($stmt->if);
            $op->block2 = $this->compileCfgBlock($stmt->else);
            $op->block1->parents[] = $block;
            $op->block2->parents[] = $block;
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\Switch_) {
            $canBeSwitch = true;
            $type = null;
            foreach ($stmt->cases as $case) {
                if (!$case instanceof Operand\Literal) {
                    $canBeSwitch = false;
                    break;
                }
                if (is_null($type)) {
                    $type = $case->type;
                } elseif (!$type->equals($case->type)) {
                    $canBeSwitch = false;
                }
            }
            if ($canBeSwitch) {
                $this->compileSwitchStmt($stmt, $block);
            } else {
                $this->compileSwitchToIfBlocks($stmt, $block);
            }
        } else {
            throw new \LogicException("Unknown Stmt Type: " . $stmt->getType());
        }
    }

    protected function compileSwitchStmt(Op\Stmt\Switch_ $switch, Block $block): void {
        $op = $this->compileOperand($switch->cond, $block, true);
        foreach ($switch->cases as $key => $case) {
            $caseOp = new OpCode(
                OpCode::TYPE_CASE,
                $op,
                $this->compileOperand($case, $block, true)
            );
            $caseOp->block1 = $this->compileCfgBlock($switch->targets[$key]);
            $caseOp->block1->parents[] = $block;
            $block->addOpCode($caseOp);
        }
        $defaultOp = new OpCode(OpCode::TYPE_JUMP);
        $defaultOp->block1 = $this->compileCfgBlock($switch->default);
        $defaultOp->block1->parents[] = $block;
        $block->addOpCode($defaultOp);
    }

    protected function getOpCodeTypeFromBinaryOp(Op\Expr\BinaryOp $expr): int {
        if ($expr instanceof Op\Expr\BinaryOp\Concat) {
            return OpCode::TYPE_CONCAT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Plus) {
            return OpCode::TYPE_PLUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Smaller) {
            return OpCode::TYPE_SMALLER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Greater) {
            return OpCode::TYPE_GREATER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\SmallerOrEqual) {
            return OpCode::TYPE_SMALLER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\GreaterOrEqual) {
            return OpCode::TYPE_GREATER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Equal) {
            return OpCode::TYPE_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotEqual) {
            return OpCode::TYPE_NOT_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Identical) {
            return OpCode::TYPE_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotIdentical) {
            return OpCode::TYPE_NOT_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Spaceship) {
            return OpCode::TYPE_SPACESHIP;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Minus) {
            return OpCode::TYPE_MINUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mul) {
            return OpCode::TYPE_MUL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Div) {
            return OpCode::TYPE_DIV;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mod) {
            return OpCode::TYPE_MODULO;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Pow) {
            return OpCode::TYPE_POW;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseAnd) {
            return OpCode::TYPE_BITWISE_AND;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseOr) {
            return OpCode::TYPE_BITWISE_OR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseXor) {
            return OpCode::TYPE_BITWISE_XOR;
        }
        throw new \LogicException("Unknown BinaryOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromCastOp(Op\Expr\Cast $expr): int {
        if ($expr instanceof Op\Expr\Cast\Array_) {
            return OpCode::TYPE_CAST_ARRAY;
        } elseif ($expr instanceof Op\Expr\Cast\Bool_) {
            return OpCode::TYPE_CAST_BOOL;
        } elseif ($expr instanceof Op\Expr\Cast\Double) {
            return OpCode::TYPE_CAST_FLOAT;
        } elseif ($expr instanceof Op\Expr\Cast\Int_) {
            return OpCode::TYPE_CAST_INT;
        } elseif ($expr instanceof Op\Expr\Cast\Object_) {
            return OpCode::TYPE_CAST_OBJECT;
        } elseif ($expr instanceof Op\Expr\Cast\String_) {
            return OpCode::TYPE_CAST_STRING;
        } elseif ($expr instanceof Op\Expr\Cast\Unset_) {
            return OpCode::TYPE_CAST_UNSET;
        }
        throw new \LogicException("Unknown CastOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromUnaryOp(Op\Expr $expr): int {
        if ($expr instanceof Op\Expr\UnaryMinus) {
            return OpCode::TYPE_UNARY_MINUS;
        } elseif ($expr instanceof Op\Expr\UnaryPlus) {
            return OpCode::TYPE_UNARY_PLUS;
        } elseif ($expr instanceof Op\Expr\BitwiseNot) {
            return OpCode::TYPE_BITWISE_NOT;
        } elseif ($expr instanceof Op\Expr\BooleanNot) {
            return OpCode::TYPE_BOOLEAN_NOT;
        } elseif ($expr instanceof Op\Expr\Clone_) {
            return OpCode::TYPE_CLONE;
        } elseif ($expr instanceof Op\Expr\Empty_) {
            return OpCode::TYPE_EMPTY;
        } elseif ($expr instanceof Op\Expr\Eval_) {
            return OpCode::TYPE_EVAL;
        } elseif ($expr instanceof Op\Expr\Exit_) {
            return OpCode::TYPE_EXIT;
        } elseif ($expr instanceof Op\Expr\Print_) {
            return OpCode::TYPE_PRINT;
        }
        throw new \LogicException("Unknown UnaryOp Type: " . $expr->getType());
    }

    protected function compileExpr(Op\Expr $expr, Block $block): array {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return [new OpCode(
                $this->getOpCodeTypeFromBinaryOp($expr),
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->left, $block, true),
                $this->compileOperand($expr->right, $block, true),
            )];
        } elseif ($expr instanceof Op\Expr\Cast) {
            return [new OpCode(
                $this->getOpCodeTypeFromCastOp($expr),
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->expr, $block, true),
            )];
        }
        switch (get_class($expr)) {
            case Op\Expr\Assertion::class:
                if ($expr->result instanceof Operand\Literal) {
                    //short circuit
                    return [];
                } elseif ($expr->expr === $expr->result) {
                    return [];
                }
                return [new OpCode(
                    OpCode::TYPE_TYPE_ASSERT,
                    $this->compileOperand($expr->result, $block, false),   
                    $this->compileOperand($expr->expr, $block, true) 
                )];
            case Op\Expr\Assign::class:
                return [new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $this->compileOperand($expr->result, $block, false),   
                    $this->compileOperand($expr->var, $block, false),
                    $this->compileOperand($expr->expr, $block, true) 
                )];
            case Op\Expr\UnaryMinus::class:
            case Op\Expr\UnaryPlus::class:
            case Op\Expr\BitwiseNot::class:
            case Op\Expr\BooleanNot::class:
            case Op\Expr\Clone_::class:
            case Op\Expr\Empty_::class:
            case Op\Expr\Eval_::class:
            case Op\Expr\Exit_::class:
                $exitExpr = null !== $expr->expr
                    ? $this->compileOperand($expr->expr, $block, true)
                    : null;

                return [new OpCode(
                    OpCode::TYPE_EXIT,
                    $this->compileOperand($expr->result, $block, false),
                    $exitExpr
                )];
            case Op\Expr\Print_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
            case Op\Expr\ArrayDimFetch::class:
                return [new OpCode(
                    OpCode::TYPE_ARRAY_DIM_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->dim, $block, true)
                )];
            case Op\Expr\ConstFetch::class:
                $nsName = null;
                if (!is_null($expr->nsName)) {
                    $nsName = $this->compileOperand($expr->nsName, $block, true);
                }
                return [new OpCode(
                    OpCode::TYPE_CONST_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->name, $block, true),
                    $nsName
                )];
            case Op\Expr\FuncCall::class:
                $return = [
                    new OpCode(
                        OpCode::TYPE_FUNCCALL_INIT,
                        $this->compileOperand($expr->name, $block, true)
                    )
                ];
                foreach ($expr->args as $arg) {
                    $return[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $this->compileOperand($arg, $block, true)
                    );
                }
                if (!empty($expr->result->usages)) {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                        $this->compileOperand($expr->result, $block, false)
                    );
                } else {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
                    );
                }
                return $return;
            case Op\Expr\StaticCall::class:
                $return = [
                    new OpCode(
                        OpCode::TYPE_STATICCALL_INIT,
                        $this->compileOperand($expr->class, $block, true),
                        $this->compileOperand($expr->name, $block, true)
                    )
                ];
                foreach ($expr->args as $arg) {
                    $return[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $this->compileOperand($arg, $block, true)
                    );
                }
                if (!empty($expr->result->usages)) {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                        $this->compileOperand($expr->result, $block, false)
                    );
                } else {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
                    );
                }
                return $return;
            case Op\Expr\New_::class:
                $return = [
                    new OpCode(
                        OpCode::TYPE_NEW,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($expr->class, $block, true),
                    )
                ];
                foreach ($expr->args as $arg) {
                    $return[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $this->compileOperand($arg, $block, true)
                    );
                }
                $return[] = new OpCode(
                    OpCode::TYPE_FUNCCALL_EXEC_NORETURN
                );
                return $return;
            case Op\Expr\PropertyFetch::class:
                return [new OpCode(
                    OpCode::TYPE_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->name, $block, true)
                )];
            case Op\Expr\Array_::class:
                $result = $this->compileOperand($expr->result, $block, false);
                if (empty($expr->values)) {
                    return [new OpCode(
                        OpCode::TYPE_INIT_ARRAY,
                        $result
                    )];
                }
                $return = [new OpCode(
                    OpCode::TYPE_INIT_ARRAY,
                    $result,
                    $this->compileOperand($expr->values[0], $block, true),
                    $this->compileOperand($expr->keys[0], $block, true)
                )];
                for ($i = 1, $n = count($expr->values); $i < $n; $i++) {
                    $return[] = new OpCode(
                        OpCode::TYPE_ADD_ARRAY_ELEMENT,
                        $result,
                        $this->compileOperand($expr->values[$i], $block, true),
                        $this->compileOperand($expr->keys[$i], $block, true)
                    );
                }
                return $return;
            case Op\Expr\Include_::class:
                return [new OpCode(
                     OpCode::TYPE_INCLUDE,
		     $this->compileOperand($expr->expr, $block, true),
		)];
            case Op\Expr\Isset_::class:
                return $this->compileIsset($expr, $block);
        }
        throw new \LogicException("Unsupported expression: " . $expr->getType());
    }

    /**
     * @return OpCode[]
     */
    protected function compileIsset(Op\Expr\Isset_ $expr, Block $block): array
    {
        assert(1 === count($expr->vars));
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        [$containerSlot, $dimSlot] = $this->resolveIssetTarget($expr->vars[0], $block);

        return [new OpCode(
            OpCode::TYPE_ISSET,
            $resultSlot,
            $containerSlot,
            $dimSlot
        )];
    }

    protected function compileCoalesce(Op\Expr\BinaryOp\Coalesce $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $rightBlock = new Block($block->orig);
        $rightBlock->inheritUndefinedLocals = true;
        $rightBlock->inheritScopeFrom($block);
        $rightSlot = $this->compileOperand($expr->right, $rightBlock, true);
        $rightBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $rightSlot
        ));

        $leftBlock = new Block($block->orig);
        $leftBlock->inheritUndefinedLocals = true;
        $leftBlock->inheritScopeFrom($block);

        $checkSlot = $this->compileBoolTemporary($block);
        $issetTarget = $this->resolveCoalesceIssetTarget($expr->left, $block);
        if (null !== $issetTarget) {
            [$containerSlot, $dimSlot] = $issetTarget;
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $containerSlot,
                $dimSlot
            ));
            $leftSlot = $this->compileOperand($expr->left, $leftBlock, true);
            $leftBlock->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $leftSlot
            ));
        } else {
            $leftSlot = $this->compileOperand($expr->left, $block, true);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $leftSlot
            ));
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $leftSlot,
                null
            ));
        }

        $leftJump = new OpCode(OpCode::TYPE_JUMP);
        $leftJump->block1 = $endBlock;
        $leftBlock->addOpCode($leftJump);
        $rightJump = new OpCode(OpCode::TYPE_JUMP);
        $rightJump->block1 = $endBlock;
        $rightBlock->addOpCode($rightJump);
        $endBlock->parents[] = $leftBlock;
        $endBlock->parents[] = $rightBlock;

        $coalesceOp = new OpCode(
            OpCode::TYPE_COALESCE,
            $resultSlot,
            $checkSlot
        );
        $coalesceOp->block1 = $leftBlock;
        $coalesceOp->block2 = $rightBlock;
        $coalesceOp->block3 = $endBlock;
        $block->addOpCode($coalesceOp);

        return $endBlock;
    }

    /**
     * @return ?array{0: int, 1: ?int}
     */
    protected function resolveCoalesceIssetTarget(Operand $operand, Block $block): ?array
    {
        if (null !== $this->unwrapArrayDimFetch($operand)) {
            return $this->resolveIssetTarget($operand, $block);
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return $this->resolveIssetTarget($operand, $block);
        }

        return null;
    }

    protected function unwrapVariableOperand(Operand $operand): ?Operand\Variable
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Operand\Variable) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Operand\Variable) {
            return $operand;
        }

        return null;
    }

    /**
     * isset($a, $b, …) with short-circuit evaluation (PHP semantics).
     * Returns the block where compilation should continue.
     */
    protected function compileIssetMulti(Op\Expr\Isset_ $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $falseSlot = $this->compileBoolConstant($block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $falseBlock = new Block($block->orig);
        $falseBlock->inheritUndefinedLocals = true;
        $falseBlock->inheritScopeFrom($block);
        $falseBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $falseJump = new OpCode(OpCode::TYPE_JUMP);
        $falseJump->block1 = $endBlock;
        $falseBlock->addOpCode($falseJump);
        $endBlock->parents[] = $falseBlock;

        $current = $block;
        $vars = $expr->vars;
        $last = count($vars) - 1;
        foreach ($vars as $i => $var) {
            [$containerSlot, $dimSlot] = $this->resolveIssetTarget($var, $block);
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            $current->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $containerSlot,
                $dimSlot
            ));
            if ($i < $last) {
                $next = new Block($block->orig);
                $next->inheritUndefinedLocals = true;
                $next->inheritScopeFrom($current);
                $jump = new OpCode(OpCode::TYPE_JUMPIF, $checkSlot);
                $jump->block1 = $next;
                $jump->block2 = $falseBlock;
                $next->parents[] = $current;
                $falseBlock->parents[] = $current;
                $current->addOpCode($jump);
                $current = $next;
            }
        }

        $doneJump = new OpCode(OpCode::TYPE_JUMP);
        $doneJump->block1 = $endBlock;
        $current->addOpCode($doneJump);
        $endBlock->parents[] = $current;

        return $endBlock;
    }

    protected function compileBoolTemporary(Block $block): int
    {
        $operand = new Temporary;
        $operand->type = Type::bool();
        // JIT assignOperandValue skips operands with empty usages (#99 coalesce branches).
        $operand->usages[] = $operand;

        return $block->getVarSlot($operand, false);
    }

    protected function compileBoolConstant(Block $block, bool $value): int
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $operand = new Operand\Temporary;
        $operand->type = Type::bool();

        return $block->registerConstant($operand, $var);
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTarget(Operand $operand, Block $block): array
    {
        $fetch = $this->unwrapArrayDimFetch($operand);
        if (null !== $fetch) {
            return [
                $this->compileOperand($fetch->var, $block, true),
                $this->compileOperand($fetch->dim, $block, true),
            ];
        }

        return [$this->compileOperand($operand, $block, true), null];
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

    protected function compileOperand(Operand $operand, Block $block, bool $isRead): ?int {
        if ($operand instanceof Operand\NullOperand) {
            return null;
        } elseif ($operand instanceof Operand\Literal) {
            assert($isRead === true);
            $mappedType = null !== $operand->type
                ? Variable::mapFromType($operand->type)
                : Variable::TYPE_UNDEFINED;
            if ($mappedType === Variable::TYPE_UNDEFINED) {
                if (is_int($operand->value)) {
                    $mappedType = Variable::TYPE_INTEGER;
                } elseif (is_float($operand->value)) {
                    $mappedType = Variable::TYPE_FLOAT;
                } elseif (is_string($operand->value)) {
                    $mappedType = Variable::TYPE_STRING;
                } elseif (is_bool($operand->value)) {
                    $mappedType = Variable::TYPE_BOOLEAN;
                } elseif (null === $operand->value) {
                    $mappedType = Variable::TYPE_NULL;
                }
            }
            $return = new Variable($mappedType);
            switch ($mappedType) {
                case Variable::TYPE_STRING:
                    $return->string($operand->value);
                    break;
                case Variable::TYPE_INTEGER:
                    $return->int($operand->value);
                    break;
                case Variable::TYPE_FLOAT:
                    $return->float($operand->value);
                    break;
                case Variable::TYPE_BOOLEAN:
                    $return->bool($operand->value);
                    break;
                case Variable::TYPE_NULL:
                    break;
                default:
                    throw new \LogicException('Unknown Literal Operand Type: ' . ($operand->type ?? 'untyped'));
            }
            return $block->registerConstant($operand, $return);
        } elseif ($operand instanceof Operand\Temporary) {
            return $block->getVarSlot($operand, $isRead);
        }
        throw new \LogicException("Unknown Operand Type: " . $operand->getType());
    }

    protected function compileTerminal(Op\Terminal $terminal, Block $block): OpCode {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $var = $this->compileOperand($terminal->expr, $block, true);
                return new OpCode(
                    OpCode::TYPE_ECHO,
                    $var
                );
            case 'Terminal_Return':
                if (is_null($terminal->expr)) {
                    return new OpCode(
                        OpCode::TYPE_RETURN_VOID
                    );    
                }
                return new OpCode(
                    OpCode::TYPE_RETURN,
                    $this->compileOperand($terminal->expr, $block, true)
                );
            default:
                throw new \LogicException("Unknown Terminal Type: " . $terminal->getType());
        }
    }

}
