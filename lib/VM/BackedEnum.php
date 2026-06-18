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
    /**
     * Like {@see caseForValue()} but returns null when the operand cannot coerce to a backing scalar (#5803).
     */
    public static function tryCaseForValue(ClassEntry $enum, Variable $value): ?EnumCaseEntry
    {
        try {
            return self::caseForValue($enum, $value);
        } catch (\TypeError) {
            return null;
        }
    }

    public static function caseForValue(ClassEntry $enum, Variable $value): ?EnumCaseEntry
    {
        if (!$enum->isEnum || null === $enum->backedType) {
            return null;
        }
        EnumSupport::ensureBackedEnumValuesUnique($enum);
        $normalized = self::normalizeBackingArgument($enum, $value->resolveIndirect());
        $match = self::matchCaseForBackingValue($enum, $normalized);
        if (null !== $match) {
            return $match;
        }

        return self::matchCaseForBackingValueFromConstants($enum, $normalized);
    }

    private static function matchCaseForBackingValue(ClassEntry $enum, Variable $normalized): ?EnumCaseEntry
    {
        foreach ($enum->enumCases as $case) {
            $backing = self::caseBackingScalar($enum->backedType, $case['value']);
            if (!self::backingValuesMatch($enum->backedType, $backing, $normalized)) {
                continue;
            }

            return new EnumCaseEntry($enum, $case['name'], clone $backing);
        }

        return null;
    }

    /**
     * Fallback when {@see ClassEntry::$enumCases} is empty or stale but case constants remain (#9603).
     *
     * @see Zend/zend_enum.c — zend_enum_from_case() backed-value hash
     */
    private static function matchCaseForBackingValueFromConstants(
        ClassEntry $enum,
        Variable $normalized
    ): ?EnumCaseEntry {
        foreach ($enum->constants as $memberLc => $stored) {
            $caseName = EnumSupport::enumCaseNameForConstantMember($enum, $memberLc);
            if (null === $caseName) {
                continue;
            }
            $backing = self::caseBackingScalar($enum->backedType, $stored);
            if (!self::backingValuesMatch($enum->backedType, $backing, $normalized)) {
                continue;
            }

            return new EnumCaseEntry($enum, $caseName, clone $backing);
        }

        return null;
    }

    /**
     * Canonical enum case variable for a matched case name (Zend singleton identity, #5533).
     */
    public static function canonicalCaseVariable(ClassEntry $enum, string $caseName): ?Variable
    {
        $lc = strtolower($caseName);
        if (!isset($enum->constants[$lc])) {
            return null;
        }
        $stored = $enum->constants[$lc];
        if (EnumCaseSupport::isEnumCaseVariable($stored)) {
            return $stored;
        }
        if (null !== $enum->backedType) {
            $resolved = $stored->resolveIndirect();
            if ($resolved->is(Variable::TYPE_INTEGER) || $resolved->is(Variable::TYPE_STRING)) {
                $match = self::tryCaseForValue($enum, $resolved);
                if (null !== $match && strcasecmp($match->caseName, $caseName) === 0) {
                    return EnumCaseSupport::createCase($enum, $match->caseName, $match->backingValue);
                }
            }
        }

        return $stored;
    }

    /**
     * Backing scalar for enumCases table entries (int/string), including legacy object storage.
     */
    public static function caseBackingScalar(string $backedType, Variable $caseValue): Variable
    {
        $caseValue = $caseValue->resolveIndirect();
        if (Variable::TYPE_OBJECT === $caseValue->type && EnumCaseSupport::isEnumCase($caseValue->toObject())) {
            $object = $caseValue->toObject();
            if (null === $object->enumCaseValue) {
                throw new \LogicException('Backed enum case object missing backing value');
            }
            $scalar = new Variable();
            $scalar->copyFrom($object->enumCaseValue);

            return $scalar;
        }
        if (Variable::TYPE_ENUM_CASE === $caseValue->type) {
            $scalar = new Variable();
            $scalar->copyFrom($caseValue->toEnumCase()->backingValue);

            return $scalar;
        }

        return $caseValue;
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
