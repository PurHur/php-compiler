<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSV delimiter/enclosure/escape validation (php-src ext/standard/file.c, issues #4530, #1193).
 */
final class VmCsvArg
{
    public static function validateFputcsvOptions(
        string $separator,
        string $enclosure,
        string $escape,
    ): void {
        self::requireSingleChar('fputcsv', $separator, 3, 'separator');
        self::requireSingleChar('fputcsv', $enclosure, 4, 'enclosure');
        self::requireEmptyOrSingleChar('fputcsv', $escape, 5, 'escape');
    }

    public static function validateFgetcsvOptions(
        string $separator,
        string $enclosure,
        string $escape,
    ): void {
        self::requireSingleChar('fgetcsv', $separator, 3, 'separator');
        self::requireSingleChar('fgetcsv', $enclosure, 4, 'enclosure');
        self::requireEmptyOrSingleChar('fgetcsv', $escape, 5, 'escape');
    }

    public static function requireSingleChar(string $function, string $value, int $argNum, string $paramName): void
    {
        if (1 !== \strlen($value)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be a single character',
                $function,
                $argNum,
                $paramName
            ));
        }
    }

    public static function requireEmptyOrSingleChar(string $function, string $value, int $argNum, string $paramName): void
    {
        if (\strlen($value) > 1) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be empty or a single character',
                $function,
                $argNum,
                $paramName
            ));
        }
    }
}
