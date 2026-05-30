<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: abstract methods cannot be private (#3548).
 *
 * php-src: Zend/zend_compile.c — zend_compile_method abstract + visibility checks
 */
final class AbstractMethodVisibilityCheck
{
    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\ClassLike) {
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
            if (!$this->isAbstractMethod($member)) {
                continue;
            }
            if (0 === ($member->func->flags & CfgFunc::FLAG_PRIVATE)) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                "Abstract function {$classDisplay}::{$member->func->name}() cannot be declared private"
            );
        }
    }

    private function isAbstractMethod(Op\Stmt\ClassMethod $method): bool
    {
        if (0 !== ($method->func->flags & CfgFunc::FLAG_ABSTRACT)) {
            return true;
        }
        $cfg = $method->func->cfg;
        if (null === $cfg) {
            return true;
        }

        return [] === $cfg->children;
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
