<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for BackedEnum::from() / ::tryFrom() (#10273, php-in-PHP).
 *
 * php-src: Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 * SSOT: {@see BackedEnum}, {@see EnumFromHandler}
 */
final class EnumFromJitHelper
{
    public static function stringBackingFromString(string $value): string
    {
        return $value;
    }

    public static function stringBackingFromLong(int $value): string
    {
        return (string) $value;
    }

    public static function stringBackingFromDouble(float $value): string
    {
        return (string) $value;
    }

    public static function stringBackingFromBool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public static function stringBackingFromNull(): string
    {
        return '';
    }

    public static function intBackingFromLong(int $value): int
    {
        return $value;
    }

    public static function intBackingFromDouble(float $value): int
    {
        if (!is_finite($value) || (float) (int) $value !== $value) {
            throw new \TypeError('mixed is not a valid backing coercion for int enum');
        }

        return (int) $value;
    }

    public static function intBackingFromString(string $enumName, string $value): int
    {
        if ('' === $value || !is_numeric($value)) {
            throw new \TypeError(
                $enumName.'::from(): Argument #1 ($value) must be of type int, string given'
            );
        }

        return (int) (float) $value;
    }

    /** Packed with NUL — JIT compile embeds declaration-order string backings. */
    public static function matchStringBackingPacked(string $needle, string $packed, int $count): int
    {
        if ($count <= 0) {
            return -1;
        }
        $parts = explode("\0", $packed);
        for ($i = 0; $i < $count; ++$i) {
            if (isset($parts[$i]) && $needle === $parts[$i]) {
                return $i;
            }
        }

        return -1;
    }

    /** Comma-separated int backings — JIT compile embeds declaration-order values. */
    public static function matchIntBackingCsv(int $needle, string $csv): int
    {
        if ('' === $csv) {
            return -1;
        }
        foreach (explode(',', $csv) as $index => $part) {
            if ($needle === (int) $part) {
                return $index;
            }
        }

        return -1;
    }

    public static function formatStringValueError(string $repr, string $enumName): string
    {
        return '"'.$repr.'" is not a valid backing value for enum '.$enumName;
    }

    public static function formatIntValueError(int $repr, string $enumName): string
    {
        return $repr.' is not a valid backing value for enum '.$enumName;
    }

    public static function stringTypeErrorSuffix(): string
    {
        return 'Argument #1 ($value) must be of type string';
    }

    public static function intTypeErrorSuffix(string $enumName): string
    {
        return $enumName.'::from(): Argument #1 ($value) must be of type int, mixed given';
    }
}
