<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Shared mbstring VM helpers (php-src ext/mbstring/mbstring.c; #7014, #3239).
 */
final class VmMbstring
{
    public static function coerceModeArg(Variable $var, string $function, int $argIndex = 1): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return self::validateMode($var->toInt(), $function, $argIndex);
    }

    public static function coerceEncodingArg(
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $default = 'UTF-8'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return $default;
        }

        return self::coerceEncodingString($var, $function, $argIndex);
    }

    public static function coerceEncodingString(Variable $var, string $function, int $argIndex = 2): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function validateMode(int $mode, string $function, int $argIndex = 1): int
    {
        if ($mode < MbstringConstants::MB_CASE_UPPER || $mode > MbstringConstants::MB_CASE_TITLE) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($mode) must be one of the MB_CASE_* constants',
                $function,
                $argIndex + 1
            ));
        }

        return $mode;
    }

    public static function convertCase(string $source, int $mode, string $encoding = 'UTF-8'): string
    {
        if (\function_exists('mb_convert_case')) {
            return \mb_convert_case($source, $mode, $encoding);
        }

        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_convert_case() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER => self::asciiUpper($source),
            MbstringConstants::MB_CASE_LOWER => self::asciiLower($source),
            MbstringConstants::MB_CASE_TITLE => self::asciiTitle($source),
            default => throw new \ValueError('mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'),
        };
    }

    private static function asciiUpper(string $source): string
    {
        return strtr($source, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    private static function asciiLower(string $source): string
    {
        return strtr($source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function asciiTitle(string $source): string
    {
        return ucwords(self::asciiLower($source));
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
