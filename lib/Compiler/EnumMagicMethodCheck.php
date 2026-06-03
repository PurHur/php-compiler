<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Disallowed magic methods on enum declarations (#5055).
 *
 * php-src: Zend/zend_compile.c — enum magic method registration
 */
final class EnumMagicMethodCheck
{
    /** @var list<string> lowercase magic names forbidden on enums */
    private const DISALLOWED_MAGIC = [
        '__tostring',
    ];

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
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodName = $member->func->name;
            if (!is_string($methodName)) {
                continue;
            }
            if (!in_array(strtolower($methodName), self::DISALLOWED_MAGIC, true)) {
                continue;
            }
            $this->fatal(
                $member,
                "Enum {$enumDisplay} cannot include magic method {$methodName}"
            );
        }
    }

    private function fatal(Op\Stmt\ClassMethod $method, string $message): void
    {
        throw new CompileFatal(
            $method->getFile(),
            $method->getLine(),
            $message
        );
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
