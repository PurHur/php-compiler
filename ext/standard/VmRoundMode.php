<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * round() mode resolution — int legacy flags or RoundingMode enum (PHP 8.4, php-src math.c).
 *
 * php-src: ext/standard/math.c — php_math_round_mode_from_enum()
 */
final class VmRoundMode
{
    public static function resolveRoundModeArg(Variable $var, string $fn, string $paramName = 'mode'): int
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryRoundModeInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #3 ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #3 ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }

    public static function tryRoundModeInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isRoundingModeEnum($enumClass->name)) {
            return null;
        }
        $caseName = EnumCaseSupport::enumCaseNameForVariable($var);
        if ('' === $caseName) {
            throw new \LogicException('RoundingMode case missing entry');
        }

        return self::roundModeIntFromCaseName($caseName);
    }

    public static function roundModeIntFromCaseName(string $caseName): int
    {
        return match ($caseName) {
            'HalfAwayFromZero' => StdlibConstants::PHP_ROUND_HALF_UP,
            'HalfTowardsZero' => StdlibConstants::PHP_ROUND_HALF_DOWN,
            'HalfEven' => StdlibConstants::PHP_ROUND_HALF_EVEN,
            'HalfOdd' => StdlibConstants::PHP_ROUND_HALF_ODD,
            'TowardsZero' => StdlibConstants::PHP_ROUND_TOWARD_ZERO,
            'AwayFromZero' => StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO,
            'NegativeInfinity' => StdlibConstants::PHP_ROUND_FLOOR,
            'PositiveInfinity' => StdlibConstants::PHP_ROUND_CEILING,
            default => throw new \ValueError('Invalid RoundingMode enum case '.$caseName),
        };
    }

    private static function isRoundingModeEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'RoundingMode');
    }
}
