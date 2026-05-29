<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * BackedEnum::from / tryFrom lookup (Zend parity, #3114).
 *
 * @see Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 */
final class BackedEnum
{
    public static function caseForValue(ClassEntry $enum, Variable $value): ?EnumCaseEntry
    {
        if (!$enum->isEnum || null === $enum->backedType) {
            return null;
        }
        $value = $value->resolveIndirect();
        foreach ($enum->enumCases as $case) {
            $backing = $case['value'];
            if (!self::backingValuesMatch($enum->backedType, $backing, $value)) {
                continue;
            }

            return new EnumCaseEntry($enum, $case['name'], clone $backing);
        }

        return null;
    }

    public static function valueErrorMessage(ClassEntry $enum, Variable $value): string
    {
        $value = $value->resolveIndirect();
        $repr = match ($value->type) {
            Variable::TYPE_STRING => '"'.$value->toString().'"',
            Variable::TYPE_INTEGER => (string) $value->toInt(),
            Variable::TYPE_FLOAT => (string) $value->toFloat(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? 'true' : 'false',
            Variable::TYPE_NULL => 'null',
            default => 'unknown',
        };

        return $repr.' is not a valid backing value for enum "'.$enum->name.'"';
    }

    private static function backingValuesMatch(string $backedType, Variable $caseValue, Variable $arg): bool
    {
        $caseValue = $caseValue->resolveIndirect();
        $arg = $arg->resolveIndirect();
        if ('int' === $backedType) {
            if (Variable::TYPE_INTEGER !== $caseValue->type || Variable::TYPE_INTEGER !== $arg->type) {
                return false;
            }

            return $caseValue->toInt() === $arg->toInt();
        }
        if ('string' === $backedType) {
            if (Variable::TYPE_STRING !== $caseValue->type || Variable::TYPE_STRING !== $arg->type) {
                return false;
            }

            return $caseValue->toString() === $arg->toString();
        }

        return false;
    }
}
