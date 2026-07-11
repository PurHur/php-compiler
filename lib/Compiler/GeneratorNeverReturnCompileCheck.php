<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: generator functions cannot declare return type never (#7351) or void (#11666).
 *
 * php-src: Zend/zend_compile.c — generator return type must be a supertype of Generator
 */
final class GeneratorNeverReturnCompileCheck
{
    public const MESSAGE = 'Generator return type must be a supertype of Generator, never given';

    public const VOID_MESSAGE = 'Generator return type must be a supertype of Generator, void given';

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
        $invalidType = $this->invalidGeneratorReturnTypeName($func);
        if (null === $invalidType) {
            return;
        }
        if (!$this->cfgContainsYieldOpcode($func->cfg)) {
            return;
        }
        $callable = $func->callableOp;
        throw new CompileFatal(
            $callable instanceof Op ? $callable->getFile() : 'unknown',
            $callable instanceof Op ? $callable->getLine() : 1,
            self::messageForInvalidReturnType($invalidType)
        );
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $invalidType = $this->invalidGeneratorReturnTypeName($member->func);
            if (null === $invalidType) {
                continue;
            }
            if (!$this->methodIsGenerator($member)) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                self::messageForInvalidReturnType($invalidType)
            );
        }
    }

    private function methodIsGenerator(Op\Stmt\ClassMethod $method): bool
    {
        return $this->cfgContainsYieldOpcode($method->func->cfg);
    }

    public static function messageForInvalidReturnType(string $typeName): string
    {
        return 'never' === $typeName
            ? self::MESSAGE
            : sprintf('Generator return type must be a supertype of Generator, %s given', $typeName);
    }

    private function invalidGeneratorReturnTypeName(Func $func): ?string
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Never_) {
            return 'never';
        }
        if ($returnType instanceof Op\Type\Literal && 'never' === strtolower($returnType->name)) {
            return 'never';
        }
        if ($returnType instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($returnType instanceof Op\Type\Literal && 'void' === strtolower($returnType->name)) {
            return 'void';
        }

        return null;
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
