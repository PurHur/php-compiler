<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: backed enum cases must declare an explicit scalar value (#5397).
 *
 * php-src: Zend/zend_compile.c — zend_compile_enum_case
 */
final class EnumBackedCaseCheck
{
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
        if (null === $enum->backedType) {
            return;
        }
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (property_exists($member, 'isEnumCase') && !$member->isEnumCase) {
                continue;
            }
            if ($this->enumCaseHasExplicitValue($member)) {
                continue;
            }
            $caseName = $this->operandDisplayName($member->name, 'case');
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                "Enum case {$enumDisplay}::{$caseName} must have a value"
            );
        }
    }

    private function enumCaseHasExplicitValue(Op\Terminal\Const_ $member): bool
    {
        if (property_exists($member, 'enumCaseHasExplicitValue') && $member->enumCaseHasExplicitValue) {
            return true;
        }

        return [] !== $member->valueBlock->children;
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
