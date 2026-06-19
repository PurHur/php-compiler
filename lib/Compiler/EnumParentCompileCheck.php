<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: parent:: when current class scope has no parent (#5410, #7381).
 *
 * php-src: Zend/zend_compile.c — parent fetch when current scope has no parent
 */
final class EnumParentCompileCheck
{
    public const MESSAGE = 'Cannot use "parent" when current class scope has no parent';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $check->validateScopeWithoutParent($child);
            } elseif ($child instanceof Op\Stmt\Class_ && null === $child->extends) {
                $check->validateScopeWithoutParent($child);
            } elseif ($child instanceof Op\Stmt\Interface_ && [] === $child->extends) {
                $check->validateScopeWithoutParent($child);
            }
        }
    }

    private function validateScopeWithoutParent(Op\Stmt\ClassLike $class): void
    {
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\ClassMethod) {
                $this->validateMethod($member);
            } elseif ($member instanceof Op\Terminal\Const_) {
                $valueBlock = $member->valueBlock;
                if (null !== $valueBlock && [] !== $valueBlock->children) {
                    $this->walkCfg($valueBlock);
                }
            }
        }
    }

    private function validateMethod(Op\Stmt\ClassMethod $method): void
    {
        $cfg = $method->func->cfg;
        if (null === $cfg || [] === $cfg->children) {
            return;
        }
        $this->walkCfg($cfg);
    }

    private function walkCfg(CfgBlock $entry): void
    {
        $seen = new \SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $block = array_shift($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->children as $op) {
                if ($this->opUsesParent($op)) {
                    throw new CompileFatal(
                        $op->getFile(),
                        max(1, $op->getLine()),
                        self::MESSAGE
                    );
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }
    }

    private function opUsesParent(Op $op): bool
    {
        if ($op instanceof Op\Expr\ClassConstFetch) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\StaticPropertyFetch) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\StaticCall) {
            return $this->operandIsParent($op->class);
        }
        if ($op instanceof Op\Expr\New_) {
            return $this->operandIsParent($op->class);
        }

        return false;
    }

    private function operandIsParent(Operand $op): bool
    {
        $name = $this->staticNameFromOperand($op);

        return null !== $name && 'parent' === strtolower($name);
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }
}
