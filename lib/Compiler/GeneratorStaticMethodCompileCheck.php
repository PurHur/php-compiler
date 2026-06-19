<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: generators (yield / :Generator) in static methods (#4938).
 *
 * php-src: Zend/zend_compile.c — zend_compile_generator, static function checks
 */
final class GeneratorStaticMethodCompileCheck
{
    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                $check->validateClassLike($child);
            } elseif ($child instanceof Op\Stmt\Trait_) {
                $check->validateClassLike($child);
            }
        }
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        $classDisplay = $this->operandDisplayName($class->name, 'class');
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (!$this->isStaticMethod($member)) {
                continue;
            }
            if ($class instanceof Op\Stmt\Interface_ && !$this->methodHasBody($member)) {
                continue;
            }
            if (!$this->methodIsGenerator($member)) {
                continue;
            }
            $methodName = $member->func->name;
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                sprintf(
                    'Generator return type is not compatible with static function %s::%s()',
                    $classDisplay,
                    $methodName
                )
            );
        }
    }

    private function isStaticMethod(Op\Stmt\ClassMethod $method): bool
    {
        return (($method->func->flags ?? 0) & Func::FLAG_STATIC) !== 0;
    }

    private function methodIsGenerator(Op\Stmt\ClassMethod $method): bool
    {
        return $this->funcDeclReturnTypeIsGenerator($method->func)
            || $this->cfgContainsYieldOpcode($method->func->cfg);
    }

    private function methodHasBody(Op\Stmt\ClassMethod $method): bool
    {
        $cfg = $method->func->cfg;

        return null !== $cfg && [] !== $cfg->children;
    }

    private function funcDeclReturnTypeIsGenerator(Func $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Literal) {
            return 'Generator' === $returnType->name;
        }
        if ($returnType instanceof Op\Type\Reference) {
            $decl = $returnType->declaration;

            return $decl instanceof Operand\Literal
                && is_string($decl->value)
                && 'Generator' === $decl->value;
        }

        return false;
    }

    private function cfgContainsYieldOpcode(?CfgBlock $entry): bool
    {
        if (null === $entry) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $block = array_shift($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->children as $op) {
                if ($op instanceof Op\Expr\Yield_ || $op instanceof Op\Expr\YieldFrom) {
                    return true;
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }

        return false;
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
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
