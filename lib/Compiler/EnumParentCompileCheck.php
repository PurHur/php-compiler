<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: parent:: inside enum scope has no parent class (#5410).
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
                $check->validateEnum($child);
            }
        }
    }

    private function validateEnum(Op\Stmt\Enum_ $enum): void
    {
        foreach ($enum->stmts->children as $member) {
            if ($member instanceof Op\Stmt\ClassMethod) {
                $this->validateMethod($member);
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
                foreach ($op->getSubBlocks() as $name) {
                    $sub = $op->{$name};
                    if (null === $sub) {
                        continue;
                    }
                    foreach (is_array($sub) ? $sub : [$sub] as $subBlock) {
                        if ($subBlock instanceof CfgBlock) {
                            $queue[] = $subBlock;
                        }
                    }
                }
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
