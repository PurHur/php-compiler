<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: abstract methods must not have bodies (#22927).
 *
 * php-src: Zend/zend_compile.c — abstract method body check during compile
 */
final class AbstractMethodBodyCheck
{
    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\ClassLike) {
                continue;
            }
            // Interfaces: InterfaceMethodBodyCheck covers any method body (#14890).
            if ($child instanceof Op\Stmt\Interface_) {
                continue;
            }
            $check->validateClassLike($child);
        }
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        $classDisplay = $this->operandDisplayName($class->name, 'class');
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (0 === ($member->func->flags & CfgFunc::FLAG_ABSTRACT)) {
                continue;
            }
            if (null === $member->func->cfg) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                sprintf(
                    'Abstract function %s::%s() cannot contain body',
                    $classDisplay,
                    $member->func->name
                )
            );
        }
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
