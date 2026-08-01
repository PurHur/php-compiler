<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal as OperandLiteral;
use PHPCfg\Script;

/**
 * Compile-time check: backed enum cases must declare an explicit scalar value (#5397).
 * Unit (non-backed) enum cases must not declare a value (#26382, zend_compile.c).
 * Duplicate case names are rejected at compile (#5218, zend_compile.c).
 * Enum case names share the class-constant table with user `const` (#26557, zend_compile.c).
 * Enum backing type must be int or string (#26539, zend_compile.c).
 * Duplicate backing values are validated at first case use via {@see \PHPCompiler\VM\EnumSupport::ensureBackedEnumValuesUnique()} (#5773, #8687, zend_enum.c).
 *
 * php-src: Zend/zend_enum.c — zend_register_enum_case; Zend/zend_compile.c — enum case registration / zend_compile_enum
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
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        $this->validateDuplicateCaseNames($enum, $enumDisplay);
        $this->assertValidEnumBackingType($enum);

        if (null === $enum->backedType || !$enum->backedType instanceof Op\Type\Literal) {
            $this->validateUnitEnumCasesHaveNoValue($enum, $enumDisplay);

            return;
        }
        $backedType = strtolower($enum->backedType->name);
        if ('int' !== $backedType && 'string' !== $backedType) {
            // Unreachable: assertValidEnumBackingType rejects peers first (#26539).
            return;
        }
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (!$this->memberIsEnumCase($member, $enum)) {
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
        }
    }

    /**
     * Zend: "Enum backing type must be int or string, %s given" (#26539, zend_compile.c).
     */
    private function assertValidEnumBackingType(Op\Stmt\Enum_ $enum): void
    {
        if (null === $enum->backedType) {
            return;
        }
        if ($enum->backedType instanceof Op\Type\Literal) {
            $name = $enum->backedType->name;
            $lc = strtolower($name);
            if ('int' === $lc || 'string' === $lc) {
                return;
            }
            throw new CompileFatal(
                $enum->getFile(),
                $enum->getLine(),
                sprintf(
                    'Enum backing type must be int or string, %s given',
                    $this->zendBackingTypeGivenLabel($name)
                )
            );
        }

        // Union / nullable / other composite types are never legal backing types.
        throw new CompileFatal(
            $enum->getFile(),
            $enum->getLine(),
            sprintf(
                'Enum backing type must be int or string, %s given',
                $this->formatCompositeBackingTypeGiven($enum->backedType)
            )
        );
    }

    /**
     * Zend type printer for the ", %s given" fragment (iterable → Traversable|array).
     */
    private function zendBackingTypeGivenLabel(string $name): string
    {
        if (0 === strcasecmp($name, 'iterable')) {
            return 'Traversable|array';
        }

        return ltrim($name, '\\');
    }

    private function formatCompositeBackingTypeGiven(Op\Type $type): string
    {
        if ($type instanceof Op\Type\Nullable) {
            $inner = $type->subtype ?? null;
            if ($inner instanceof Op\Type\Literal) {
                return '?' . $this->zendBackingTypeGivenLabel($inner->name);
            }

            return '?mixed';
        }
        if ($type instanceof Op\Type\Union_) {
            $parts = [];
            foreach ($type->types as $part) {
                if ($part instanceof Op\Type\Literal) {
                    $parts[] = $this->zendBackingTypeGivenLabel($part->name);
                }
            }
            if ([] !== $parts) {
                return implode('|', $parts);
            }
        }

        return 'mixed';
    }

    /**
     * Zend: "Case A of non-backed enum E must not have a value" (#26382, zend_compile.c).
     */
    private function validateUnitEnumCasesHaveNoValue(Op\Stmt\Enum_ $enum, string $enumDisplay): void
    {
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (!$this->memberIsEnumCase($member, $enum)) {
                continue;
            }
            if (!$this->enumCaseHasExplicitValue($member, $enum)) {
                continue;
            }
            $caseName = $this->operandDisplayName($member->name, 'case');
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                "Case {$caseName} of non-backed enum {$enumDisplay} must not have a value"
            );
        }
    }

    /**
     * Zend rejects duplicate enum case names and case↔const collisions at compile time
     * (#5218, #26557, zend_compile.c). Cases occupy the same name table as user `const`.
     */
    private function validateDuplicateCaseNames(Op\Stmt\Enum_ $enum, string $enumDisplay): void
    {
        /** @var array<string, true> */
        $seen = [];
        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            // Enum case / class constant names are case-sensitive (Zend/zend_compile.c / zend_enum.c, #25929).
            $name = $this->operandDisplayName(
                $member->name,
                $this->memberIsEnumCase($member, $enum) ? 'case' : 'const'
            );
            if (isset($seen[$name])) {
                throw new CompileFatal(
                    $member->getFile(),
                    $member->getLine(),
                    sprintf('Cannot redefine class constant %s::%s', $enumDisplay, $name)
                );
            }
            $seen[$name] = true;
        }
    }

    private function memberIsEnumCase(Op\Terminal\Const_ $member, Op\Stmt\Enum_ $enum): bool
    {
        if (property_exists($member, 'isEnumCase')) {
            return $member->isEnumCase;
        }
        if (property_exists($member, 'declaredType') && null !== $member->declaredType) {
            return false;
        }
        $flags = property_exists($member, 'flags') ? (int) $member->flags : 0;
        if (0 !== ($flags & (\PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE | \PHPCfg\Func::FLAG_FINAL))) {
            return false;
        }
        if (null === $enum->backedType || !$enum->backedType instanceof Op\Type\Literal) {
            return 0 === $flags;
        }
        $backedType = $enum->backedType->name;
        if ('int' !== $backedType && 'string' !== $backedType) {
            return false;
        }
        $literal = $this->literalFromEnumCaseValue($member);
        if (null === $literal) {
            return false;
        }
        if ('int' === $backedType) {
            return \is_int($literal->value);
        }

        return \is_string($literal->value);
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
