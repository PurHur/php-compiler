<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Reject user redeclarations of enum builtin methods (#26502).
 *
 * Zend injects `cases()` on every enum and `from()` / `tryFrom()` on backed
 * enums; a user method with the same name (case-insensitive) is a compile
 * fatal: `Cannot redeclare E::cases()` (canonical lowercase in the message).
 *
 * Trait-imported methods with these names are allowed (php-src does not treat
 * them as redeclarations of the injected builtins at compile time).
 *
 * php-src: Zend/zend_enum.c — builtin method registration;
 * Zend/zend_compile.c — duplicate method / reserved enum method checks.
 */
final class EnumBuiltinMethodRedeclareCheck
{
    /** All enums — UnitEnum::cases() */
    private const ALL_ENUM_RESERVED = [
        'cases' => 'cases',
    ];

    /** Backed enums only — BackedEnum::from() / tryFrom() */
    private const BACKED_ENUM_RESERVED = [
        'from' => 'from',
        'tryfrom' => 'tryfrom',
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
        $reserved = self::ALL_ENUM_RESERVED;
        if (null !== $enum->backedType) {
            $reserved = array_merge($reserved, self::BACKED_ENUM_RESERVED);
        }

        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodName = $member->func->name;
            if (!is_string($methodName)) {
                continue;
            }
            $lc = strtolower($methodName);
            if (!isset($reserved[$lc])) {
                continue;
            }
            $this->fatal(
                $member,
                sprintf('Cannot redeclare %s::%s()', $enumDisplay, $reserved[$lc])
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
