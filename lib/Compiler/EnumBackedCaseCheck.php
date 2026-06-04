<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal as OperandLiteral;
use PHPCfg\Script;

/**
 * Compile-time check: backed enum cases must declare an explicit scalar value (#5397)
 * and backing scalars must be unique (#5710).
 *
 * php-src: Zend/zend_enum.c — zend_register_enum_case
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
        if (null === $enum->backedType || !$enum->backedType instanceof Op\Type\Literal) {
            return;
        }
        $backedType = $enum->backedType->name;
        if ('int' !== $backedType && 'string' !== $backedType) {
            return;
        }
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        /** @var array<int|string, string> */
        $seenBacking = [];
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (property_exists($member, 'isEnumCase') && !$member->isEnumCase) {
                continue;
            }
            $caseName = $this->operandDisplayName($member->name, 'case');
            if (!$this->enumCaseHasExplicitValue($member, $enum)) {
                throw new CompileFatal(
                    $member->getFile(),
                    $member->getLine(),
                    "Enum case {$enumDisplay}::{$caseName} must have a value"
                );
            }
            $backingKey = $this->compileTimeEnumCaseBackingKey($member, $backedType);
            if (null === $backingKey) {
                continue;
            }
            if (isset($seenBacking[$backingKey])) {
                throw new CompileFatal(
                    $member->getFile(),
                    $member->getLine(),
                    sprintf(
                        'Duplicate value in enum %s for cases %s and %s',
                        $enumDisplay,
                        $seenBacking[$backingKey],
                        $caseName
                    )
                );
            }
            $seenBacking[$backingKey] = $caseName;
        }
    }

    /**
     * @return int|string|null backing scalar when known at compile time
     */
    private function compileTimeEnumCaseBackingKey(Op\Terminal\Const_ $member, string $backedType): int|string|null
    {
        $value = $member->value;
        if (!$value instanceof OperandLiteral) {
            return null;
        }
        $literal = $value->value;
        if ('int' === $backedType) {
            if (is_int($literal)) {
                return $literal;
            }
            if (is_float($literal) && (float) (int) $literal === $literal) {
                return (int) $literal;
            }

            return null;
        }
        if (is_string($literal)) {
            return $literal;
        }

        return null;
    }

    private function enumCaseHasExplicitValue(Op\Terminal\Const_ $member, Op\Stmt\Enum_ $enum): bool
    {
        if (property_exists($member, 'enumCaseHasExplicitValue') && $member->enumCaseHasExplicitValue) {
            return true;
        }

        if ([] !== $member->valueBlock->children) {
            return true;
        }

        return $this->valueOperandImpliesExplicitEnumCaseValue($member);
    }

    /**
     * When php-cfg #5397 overlay is missing, parseEnumCase still stores the initializer in
     * {@see Op\Terminal\Const_::$value} but leaves {@see Op\Terminal\Const_::$valueBlock} empty
     * and does not set enumCaseHasExplicitValue. Infer explicit `= expr` from the value operand.
     */
    private function valueOperandImpliesExplicitEnumCaseValue(Op\Terminal\Const_ $member): bool
    {
        $value = $member->value;
        if (!$value instanceof OperandLiteral) {
            return true;
        }

        $literal = $value->value;
        if (is_int($literal) || is_float($literal) || is_bool($literal)) {
            return true;
        }

        if (!is_string($literal)) {
            return true;
        }

        $caseName = $this->operandDisplayName($member->name, '');
        if ('' !== $caseName && $literal === $caseName) {
            return false;
        }

        return true;
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
