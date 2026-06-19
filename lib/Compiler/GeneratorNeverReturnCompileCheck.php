<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: generator functions cannot declare return type never (#7351).
 *
 * php-src: Zend/zend_compile.c — generator return type must be a supertype of Generator
 */
final class GeneratorNeverReturnCompileCheck
{
    public const MESSAGE = 'Generator return type must be a supertype of Generator, never given';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->functions as $func) {
            $check->validateFunc($func);
        }
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\ClassLike) {
                $check->validateClassLike($child);
            }
        }
    }

    private function validateFunc(Func $func): void
    {
        if (!$this->funcDeclReturnTypeIsNever($func)) {
            return;
        }
        if (!$this->cfgContainsYieldOpcode($func->cfg)) {
            return;
        }
        $callable = $func->callableOp;
        throw new CompileFatal(
            $callable instanceof Op ? $callable->getFile() : 'unknown',
            $callable instanceof Op ? $callable->getLine() : 1,
            self::MESSAGE
        );
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (!$this->funcDeclReturnTypeIsNever($member->func)) {
                continue;
            }
            if (!$this->methodIsGenerator($member)) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                self::MESSAGE
            );
        }
    }

    private function methodIsGenerator(Op\Stmt\ClassMethod $method): bool
    {
        return $this->cfgContainsYieldOpcode($method->func->cfg);
    }

    private function funcDeclReturnTypeIsNever(Func $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Never_) {
            return true;
        }
        if ($returnType instanceof Op\Type\Literal && 'never' === strtolower($returnType->name)) {
            return true;
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
}
