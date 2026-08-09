<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * round() mode resolution — int legacy flags or RoundingMode enum (PHP 8.4, php-src math.c).
 *
 * php-src: ext/standard/math.c — php_math_round_mode_from_enum()
 */
final class VmRoundMode
{
    /** @var array<int, true> */
    private const VALID_LEGACY_INT_MODES = [
        StdlibConstants::PHP_ROUND_HALF_UP => true,
        StdlibConstants::PHP_ROUND_HALF_DOWN => true,
        StdlibConstants::PHP_ROUND_HALF_EVEN => true,
        StdlibConstants::PHP_ROUND_HALF_ODD => true,
        StdlibConstants::PHP_ROUND_CEILING => true,
        StdlibConstants::PHP_ROUND_FLOOR => true,
        StdlibConstants::PHP_ROUND_TOWARD_ZERO => true,
        StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO => true,
    ];

    public static function isValidLegacyIntMode(int $mode): bool
    {
        return isset(self::VALID_LEGACY_INT_MODES[$mode]);
    }

    public static function invalidModeMessage(string $fn, int $argNum, string $paramName): string
    {
        return sprintf(
            '%s(): Argument #%d ($%s) must be a valid rounding mode (RoundingMode::*)',
            $fn,
            $argNum,
            $paramName
        );
    }

    public static function assertValidLegacyIntMode(
        int $mode,
        string $fn,
        int $argNum = 3,
        string $paramName = 'mode'
    ): void {
        if (!CompilerVersion::supportsRoundingModeEnum()) {
            return;
        }
        if (self::isValidLegacyIntMode($mode)) {
            return;
        }

        throw new \ValueError(self::invalidModeMessage($fn, $argNum, $paramName));
    }

    public static function resolveRoundModeArg(
        Variable $var,
        string $fn,
        string $paramName = 'mode',
        int $argNum = 3,
        ?Frame $frame = null
    ): int {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryRoundModeInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        // php-src Z_PARAM_LONG soft-null for RoundingMode|int — non-strict DEP+coerce to 0,
        // then ValueError (mode 0 invalid); strict_types keeps TypeError (#29384).
        if (Variable::TYPE_NULL === $var->type) {
            if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($%s) must be of type RoundingMode|int, null given',
                    $fn,
                    $argNum,
                    $paramName
                ));
            }
            VmNullNumberParamDeprecation::emit(
                $frame,
                $fn,
                $argNum,
                $paramName,
                'RoundingMode|int'
            );
            $mode = 0;
            self::assertValidLegacyIntMode($mode, $fn, $argNum, $paramName);

            return $mode;
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        $mode = $var->toInt();
        self::assertValidLegacyIntMode($mode, $fn, $argNum, $paramName);

        return $mode;
    }

    /**
     * bcround() / BcMath\Number::round() — RoundingMode only (php-src bcmath.stub.php; #28566).
     *
     * Unlike {@see resolveRoundModeArg()} (round() accepts RoundingMode|int), legacy int
     * PHP_ROUND_* flags are TypeError under PROFILE≥8.4.
     */
    public static function resolveRoundingModeOnlyArg(
        Variable $var,
        string $fn,
        string $paramName = 'mode',
        int $argNum = 3
    ): int {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryRoundModeInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($%s) must be of type RoundingMode, %s given',
            $fn,
            $argNum,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
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
