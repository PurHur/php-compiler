<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCompiler\Block;
use PHPCompiler\Compiler;
use PHPCompiler\OpCode;

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
        $this->guarded($op, fn () => parent::compileOp($op, $block));
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block): void
    {
        $this->guarded($stmt, fn () => parent::compileStmt($stmt, $block));
    }

    /**
     * @return OpCode[]
     */
    protected function compileExpr(Op\Expr $expr, Block $block): array
    {
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

    protected function compileClassBody(CfgBlock $block, int $type): Block
    {
        $result = new Block($block);
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    try {
                        if ($type !== OpCode::TYPE_DECLARE_CLASS) {
                            throw new \LogicException('Properties are only supported on classes for now');
                        }
                        if (!is_null($child->defaultBlock)) {
                            $this->compileOps($child->defaultBlock->children, $result);
                        }
                        $declared = $child->declaredType instanceof Op\Type\Literal
                            ? Type::fromDecl($child->declaredType->name)
                            : $child->type;
                        $result->addOpCode(new OpCode(
                            OpCode::TYPE_DECLARE_PROPERTY,
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
                        $methodBlock = $this->compileCfgBlock($child->func->cfg, $child->func->params);
                        $methodBlock->func = $child->func;
                        $methodName = new Operand\Literal($child->func->name);
                        $methodName->type = Type::string();
                        $declare = new OpCode(
                            OpCode::TYPE_DECLARE_METHOD,
                            $this->compileOperand($methodName, $result, true)
                        );
                        $declare->block1 = $methodBlock;
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

    protected function compileTerminal(Op\Terminal $terminal, Block $block): OpCode
    {
        try {
            return parent::compileTerminal($terminal, $block);
        } catch (\LogicException $e) {
            if ($this->recordIfUnsupported($terminal, $e)) {
                return new OpCode(OpCode::TYPE_RETURN_VOID);
            }
            throw $e;
        }
    }

    private function guarded(Op $op, callable $compile): void
    {
        try {
            $compile();
        } catch (\LogicException $e) {
            if (!$this->recordIfUnsupported($op, $e)) {
                throw $e;
            }
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
