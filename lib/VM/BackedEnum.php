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
        $normalized = self::normalizeBackingArgument($enum, $value->resolveIndirect());
        foreach ($enum->enumCases as $case) {
            $backing = $case['value'];
            if (!self::backingValuesMatch($enum->backedType, $backing, $normalized)) {
                continue;
            }

            return new EnumCaseEntry($enum, $case['name'], clone $backing);
        }

        return null;
    }

    public static function valueErrorMessage(ClassEntry $enum, Variable $value): string
    {
        $value = $value->resolveIndirect();
        try {
            $normalized = self::normalizeBackingArgument($enum, $value);
            $repr = self::formatBackingRepr($enum->backedType ?? '', $normalized);
        } catch (\TypeError) {
            $repr = self::formatRawRepr($value);
        }

        return $repr.' is not a valid backing value for enum '.$enum->name;
    }

    private static function normalizeBackingArgument(ClassEntry $enum, Variable $arg): Variable
    {
        $backedType = $enum->backedType;
        if ('int' === $backedType) {
            return self::normalizeIntBackingArgument($enum->name, $arg);
        }
        if ('string' === $backedType) {
            return self::normalizeStringBackingArgument($arg);
        }

        throw new \LogicException('Unsupported enum backing type: '.$backedType);
    }

    private static function normalizeIntBackingArgument(string $enumName, Variable $arg): Variable
    {
        $result = new Variable(Variable::TYPE_INTEGER);
        switch ($arg->type) {
            case Variable::TYPE_INTEGER:
                $result->int($arg->toInt());

                return $result;
            case Variable::TYPE_FLOAT:
                $float = $arg->toFloat();
                if (!is_finite($float) || (float) (int) $float !== $float) {
                    break;
                }
                $result->int((int) $float);

                return $result;
            case Variable::TYPE_STRING:
                $str = $arg->toString();
                if ('' === $str || !is_numeric($str)) {
                    break;
                }
                $result->int((int) (float) $str);

                return $result;
            default:
                break;
        }

        throw new \TypeError(
            $enumName.'::from(): Argument #1 ($value) must be of type int, '
            .self::typeLabel($arg).' given'
        );
    }

    private static function normalizeStringBackingArgument(Variable $arg): Variable
    {
        $result = new Variable(Variable::TYPE_STRING);
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                $result->string($arg->toString());

                return $result;
            case Variable::TYPE_INTEGER:
                $result->string((string) $arg->toInt());

                return $result;
            case Variable::TYPE_FLOAT:
                $result->string((string) $arg->toFloat());

                return $result;
            case Variable::TYPE_BOOLEAN:
                $result->string($arg->toBool() ? '1' : '0');

                return $result;
            case Variable::TYPE_NULL:
                $result->string('');

                return $result;
            default:
                break;
        }

        throw new \TypeError(
            'Argument #1 ($value) must be of type string, '.self::typeLabel($arg).' given'
        );
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

    private static function formatBackingRepr(string $backedType, Variable $value): string
    {
        if ('string' === $backedType) {
            return '"'.$value->toString().'"';
        }

        return (string) $value->toInt();
    }

    private static function formatRawRepr(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_STRING => '"'.$value->toString().'"',
            Variable::TYPE_INTEGER => (string) $value->toInt(),
            Variable::TYPE_FLOAT => (string) $value->toFloat(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? 'true' : 'false',
            Variable::TYPE_NULL => 'null',
            default => 'unknown',
        };
    }

    private static function typeLabel(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ARRAY => 'array',
            default => 'mixed',
        };
    }
}
