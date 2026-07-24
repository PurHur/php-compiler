<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;

/**
 * BackedEnum::from / tryFrom lookup (Zend parity, #3114).
 *
 * @see Zend/zend_enum.c — zend_enum_from_base(), zend_enum_get_case_by_value()
 */
final class BackedEnum
{
    /**
     * Like {@see caseForValue()} but returns null when the operand cannot coerce to a backing scalar (#5803).
     */
    public static function tryCaseForValue(
        ClassEntry $enum,
        Variable $value,
        ?Context $vmContext = null,
        ?Frame $frame = null,
        string $methodName = 'from',
    ): ?EnumCaseEntry {
        try {
            return self::caseForValue($enum, $value, $vmContext, $frame, $methodName);
        } catch (\TypeError) {
            return null;
        }
    }

    /**
     * Caller strict_types: reject non-exact backing scalars before coercion (#18476, zend_verify_arg_type).
     */
    public static function assertStrictCallerBackingArg(
        ClassEntry $enum,
        Variable $arg,
        Frame $frame,
        string $methodName,
    ): void {
        if (!InternalStrictArg::isCallerStrict($frame) || null === $enum->backedType) {
            return;
        }
        $resolved = $arg->resolveIndirect();
        $function = $enum->name.'::'.$methodName;
        if ('int' === $enum->backedType) {
            if (Variable::TYPE_INTEGER !== $resolved->type) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($value) must be of type int, %s given',
                    $function,
                    EnumCaseSupport::typeNameForVariable($resolved)
                ));
            }

            return;
        }
        if ('string' === $enum->backedType && Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($value) must be of type string, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }
    }

    public static function caseForValue(
        ClassEntry $enum,
        Variable $value,
        ?Context $vmContext = null,
        ?Frame $frame = null,
        string $methodName = 'from',
    ): ?EnumCaseEntry {
        if (!$enum->isEnum || null === $enum->backedType) {
            return null;
        }
        EnumSupport::ensureBackedEnumValuesUnique($enum);
        $normalized = self::normalizeBackingArgument(
            $enum,
            $value->resolveIndirect(),
            $vmContext,
            $frame,
            $methodName
        );
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
            // Silent normalize — deprecation already emitted in caseForValue (#22947).
            $normalized = self::normalizeBackingArgument($enum, $value, null, null);
            $repr = self::formatBackingRepr($enum->backedType ?? '', $normalized);
        } catch (\TypeError) {
            $repr = self::formatRawRepr($value);
        }

        return $repr.' is not a valid backing value for enum '.$enum->name;
    }

    private static function normalizeBackingArgument(
        ClassEntry $enum,
        Variable $arg,
        ?Context $vmContext = null,
        ?Frame $frame = null,
        string $methodName = 'from',
    ): Variable {
        $backedType = $enum->backedType;
        if ('int' === $backedType) {
            return self::normalizeIntBackingArgument($enum->name, $arg, $vmContext, $frame, $methodName);
        }
        if ('string' === $backedType) {
            return self::normalizeStringBackingArgument($arg, $vmContext, $frame);
        }

        throw new \LogicException('Unsupported enum backing type: '.$backedType);
    }

    /**
     * Z_PARAM_LONG for int-backed enums: finite float truncates; precision loss → E_DEPRECATED (#22947).
     */
    private static function normalizeIntBackingArgument(
        string $enumName,
        Variable $arg,
        ?Context $vmContext = null,
        ?Frame $frame = null,
        string $methodName = 'from',
    ): Variable {
        $result = new Variable(Variable::TYPE_INTEGER);
        switch ($arg->type) {
            case Variable::TYPE_INTEGER:
                $result->int($arg->toInt());

                return $result;
            case Variable::TYPE_NULL:
                // Zend zend_enum_from_base: null coerces to 0 under weak types (#20072).
                $result->int(0);

                return $result;
            case Variable::TYPE_BOOLEAN:
                // Same weak path as null/false→0, true→1 (zend_enum.c).
                $result->int($arg->toBool() ? 1 : 0);

                return $result;
            case Variable::TYPE_FLOAT:
                $result->int(self::floatToBackingLong($enumName, $arg->toFloat(), $vmContext, $frame, $methodName));

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
            $enumName.'::'.$methodName.'(): Argument #1 ($value) must be of type int, '
            .self::typeLabel($arg).' given'
        );
    }

    /**
     * Z_PARAM_STR_OR_LONG: float → long (deprecate) → zend_long_to_str (#22947).
     */
    private static function normalizeStringBackingArgument(
        Variable $arg,
        ?Context $vmContext = null,
        ?Frame $frame = null,
    ): Variable {
        $result = new Variable(Variable::TYPE_STRING);
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                $result->string($arg->toString());

                return $result;
            case Variable::TYPE_INTEGER:
                $result->string((string) $arg->toInt());

                return $result;
            case Variable::TYPE_FLOAT:
                // Weak path uses Z_PARAM_STR_OR_LONG — finite float→long→str; NAN/INF→"NAN"/"INF" (#22947).
                $float = $arg->toFloat();
                if (!is_finite($float)) {
                    $result->string((string) $float);

                    return $result;
                }
                $long = self::floatToBackingLong('BackedEnum', $float, $vmContext, $frame);
                $result->string((string) $long);

                return $result;
            case Variable::TYPE_BOOLEAN:
                $result->string($arg->toBool() ? '1' : '0');

                return $result;
            case Variable::TYPE_NULL:
                // Zend: null→"0" (same as false), not convert_to_string empty (#20072).
                $result->string('0');

                return $result;
            default:
                break;
        }

        throw new \TypeError(
            'Argument #1 ($value) must be of type string, '.self::typeLabel($arg).' given'
        );
    }

    /**
     * zend_dval_to_lval / Z_PARAM_LONG float path (NAN/INF → TypeError).
     */
    private static function floatToBackingLong(
        string $enumName,
        float $float,
        ?Context $vmContext = null,
        ?Frame $frame = null,
        string $methodName = 'from',
    ): int {
        if (!is_finite($float)) {
            throw new \TypeError(
                $enumName.'::'.$methodName.'(): Argument #1 ($value) must be of type int, float given'
            );
        }
        if (null !== $vmContext) {
            VmMath::warnFloatToIntPrecisionLoss($float, $vmContext, $frame);
        }

        return VmMath::floatToZendLong($float);
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
