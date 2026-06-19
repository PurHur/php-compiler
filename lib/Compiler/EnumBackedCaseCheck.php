<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal as OperandLiteral;
use PHPCfg\Script;

/**
 * Compile-time check: backed enum cases must declare an explicit scalar value (#5397).
 * Duplicate backing values are rejected at compile when values are known (#5773, #9677, zend_enum.c).
 *
 * php-src: Zend/zend_enum.c — zend_register_enum_case
 */
final class EnumBackedCaseCheck
{
    /**
     * Zend duplicate backing detection message, or null when values are unique (#5773, zend_enum.c).
     *
     * @param iterable<array{name: string, backing: int|string}> $cases declaration order
     */
    public static function duplicateBackingErrorMessage(
        string $enumName,
        iterable $cases
    ): ?string {
        /** @var array<int|string, string> $seen */
        $seen = [];
        foreach ($cases as $case) {
            $key = $case['backing'];
            if (isset($seen[$key])) {
                return sprintf(
                    'Duplicate value in enum %s for cases %s and %s',
                    $enumName,
                    $seen[$key],
                    $case['name']
                );
            }
            $seen[$key] = $case['name'];
        }

        return null;
    }

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
        $cases = [];
        $duplicateSite = null;
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
            $backing = $this->compileTimeBackingScalar($member, $backedType);
            if (null === $backing) {
                continue;
            }
            $cases[] = ['name' => $caseName, 'backing' => $backing];
            if (null === $duplicateSite) {
                $duplicateSite = $member;
            }
        }
        $message = self::duplicateBackingErrorMessage($enumDisplay, $cases);
        if (null !== $message && null !== $duplicateSite) {
            throw new CompileFatal(
                $duplicateSite->getFile(),
                $duplicateSite->getLine(),
                $message
            );
        }
    }

    /**
     * @return int|string|null when the case initializer is a compile-time scalar
     */
    private function compileTimeBackingScalar(Op\Terminal\Const_ $member, string $backedType): int|string|null
    {
        $literal = $this->literalFromEnumCaseValue($member);
        if (null === $literal) {
            return null;
        }
        if ('int' === $backedType) {
            if (!\is_int($literal->value)) {
                return null;
            }

            return $literal->value;
        }
        if ('string' === $backedType) {
            if (!\is_string($literal->value)) {
                return null;
            }

            return $literal->value;
        }

        return null;
    }

    private function literalFromEnumCaseValue(Op\Terminal\Const_ $member): ?OperandLiteral
    {
        $literal = $this->unwrapLiteralOperand($member->value);
        if (null !== $literal) {
            return $literal;
        }
        if ([] === $member->valueBlock->children) {
            return null;
        }
        if (1 !== \count($member->valueBlock->children)) {
            return null;
        }
        $child = $member->valueBlock->children[0];
        if ($child instanceof Op\Expr\Const_) {
            return $this->unwrapLiteralOperand($child->value);
        }

        return null;
    }

    private function unwrapLiteralOperand(Operand $operand): ?OperandLiteral
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }
        while ($operand instanceof Operand\Variable) {
            $operand = $operand->name;
            while ($operand instanceof Operand\Temporary && null !== $operand->original) {
                $operand = $operand->original;
            }
        }

        return $operand instanceof OperandLiteral ? $operand : null;
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
