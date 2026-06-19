<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\Compiler;
use PHPCompiler\MethodVisibility;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

/**
 * Compiler that records unsupported CFG nodes instead of throwing.
 */
final class LintCompiler extends Compiler
{
    /** @var list<Issue> */
    public array $issues = [];

    /** @var array<string, true> */
    private array $issueKeys = [];

    protected function compileOp(Op $op, Block $block): void
    {
        try {
            parent::compileOp($op, $block);
        } catch (\LogicException $e) {
            if (!$this->recordIfUnsupported($op, $e)) {
                throw $e;
            }
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block): void
    {
        try {
            parent::compileStmt($stmt, $block);
        } catch (\LogicException $e) {
            if (!$this->recordIfUnsupported($stmt, $e)) {
                throw $e;
            }
        }
    }

    /**
     * @return OpCode[]
     */
    protected function compileExpr(Op\Expr $expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            return [new OpCode(
                OpCode::TYPE_STATIC_PROPERTY_FETCH,
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->class, $block, true),
                $this->compileOperand($expr->name, $block, true)
            )];
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $this->compileClassConstFetch($expr, $block);
        }
        if ($expr instanceof Op\Expr\InstanceOf_) {
            return $this->compileInstanceOf($expr, $block);
        }

        try {
            return parent::compileExpr($expr, $block);
        } catch (\LogicException $e) {
            if ($this->recordIfUnsupported($expr, $e)) {
                return [];
            }
            throw $e;
        }
    }

    protected function compileClassLike(Op\Stmt\ClassLike $class, Block $block): OpCode
    {
        try {
            return parent::compileClassLike($class, $block);
        } catch (\LogicException $e) {
            if ($this->recordIfUnsupported($class, $e)) {
                return new OpCode(OpCode::TYPE_RETURN_VOID);
            }
            throw $e;
        }
    }

    protected function compileClassBody(CfgBlock $block, int $type, ?string $className = null): Block
    {
        $result = new Block($block);
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    try {
                        if (
                            OpCode::TYPE_DECLARE_CLASS !== $type
                            && OpCode::TYPE_DECLARE_INTERFACE !== $type
                            && OpCode::TYPE_DECLARE_TRAIT !== $type
                        ) {
                            throw new \LogicException('Properties are only supported on classes, interfaces, and traits for now');
                        }
                        if (OpCode::TYPE_DECLARE_INTERFACE === $type) {
                            if ($child->static && !$this->interfaceStaticPropertyHookAllowed($child->name)) {
                                throw new \LogicException('Interfaces cannot declare static properties');
                            }
                            if (!is_null($child->defaultBlock) || null !== $child->defaultVar) {
                                throw new \LogicException('Interface properties cannot have default values');
                            }
                        }
                        if (!is_null($child->defaultBlock)) {
                            $this->compileOps($child->defaultBlock->children, $result);
                        }
                        $declared = $child->declaredType instanceof Op\Type\Literal
                            ? Type::fromDecl($child->declaredType->name)
                            : $child->type;
                        $declareType = $child->static
                            ? OpCode::TYPE_DECLARE_STATIC_PROPERTY
                            : OpCode::TYPE_DECLARE_PROPERTY;
                        $result->addOpCode(new OpCode(
                            $declareType,
                            $this->compileOperand($child->name, $result, true),
                            is_null($child->defaultVar) ? null : $this->compileOperand($child->defaultVar, $result, true),
                            $this->compileTypeConstrainedVariable($result, $declared)
                        ));
                    } catch (\LogicException $e) {
                        if (!$this->recordIfUnsupported($child, $e)) {
                            throw $e;
                        }
                    }
                    break;
                case Op\Stmt\ClassMethod::class:
                    try {
                        if ('__construct' === $child->func->name) {
                            foreach ($child->func->params as $param) {
                                if ($this->isPromotedParam($param)) {
                                    $this->compilePromotedPropertyDeclaration($param, $result);
                                }
                            }
                        }
                        $methodName = new Operand\Literal($child->func->name);
                        $methodName->type = Type::string();
                        $visVar = new Variable(Variable::TYPE_INTEGER);
                        $visVar->int(MethodVisibility::mask($child->func->flags));
                        $visOperand = new Temporary;
                        $visOperand->type = Type::int();
                        $visIdx = $result->registerConstant($visOperand, $visVar);
                        $declare = new OpCode(
                            OpCode::TYPE_DECLARE_METHOD,
                            $this->compileOperand($methodName, $result, true),
                            null,
                            $visIdx
                        );
                        if (null !== $child->func->cfg) {
                            $methodBlock = $this->compileCfgBlock($child->func->cfg, $child->func->params, $child->func);
                            $declare->block1 = $methodBlock;
                        }
                        $result->addOpCode($declare);
                    } catch (\LogicException $e) {
                        if (!$this->recordIfUnsupported($child, $e)) {
                            throw $e;
                        }
                    }
                    break;
                case Op\Terminal\Const_::class:
                    try {
                        if ($type !== OpCode::TYPE_DECLARE_CLASS) {
                            throw new \LogicException('Class constants are only supported on classes for now');
                        }
                        $this->compileOps($child->valueBlock->children, $result);
                        $result->addOpCode(new OpCode(
                            OpCode::TYPE_DECLARE_CLASS_CONST,
                            $this->compileOperand($child->name, $result, true),
                            $this->compileOperand($child->value, $result, true)
                        ));
                    } catch (\LogicException $e) {
                        if (!$this->recordIfUnsupported($child, $e)) {
                            throw $e;
                        }
                    }
                    break;
                default:
                    try {
                        throw new \LogicException('Unsupported class body element: '.get_class($child));
                    } catch (\LogicException $e) {
                        if (!$this->recordIfUnsupported($child, $e)) {
                            throw $e;
                        }
                    }
            }
        }

        return $result;
    }

    /**
     * @return list<OpCode>
     */
    protected function compileTerminal(Op\Terminal $terminal, Block $block): array
    {
        try {
            return parent::compileTerminal($terminal, $block);
        } catch (\LogicException $e) {
            if ($this->recordIfUnsupported($terminal, $e)) {
                return [new OpCode(OpCode::TYPE_RETURN_VOID)];
            }
            throw $e;
        }
    }

    private function recordIfUnsupported(Op $op, \LogicException $e): bool
    {
        if (!$this->isUnsupportedMessage($e->getMessage())) {
            return false;
        }
        $issue = Issue::fromOp($op, $e->getMessage());
        $key = $issue->file.'|'.$issue->line.'|'.$issue->kind;
        if (isset($this->issueKeys[$key])) {
            return true;
        }
        $this->issueKeys[$key] = true;
        $this->issues[] = $issue;

        return true;
    }

    private function isUnsupportedMessage(string $message): bool
    {
        return str_contains($message, 'Unknown ')
            || str_contains($message, 'Unsupported ');
    }
}
