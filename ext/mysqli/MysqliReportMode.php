<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

/**
 * Global mysqli error reporting mode (php-src ext/mysqli/mysqli_report.c; #21804).
 *
 * PHP 8.1+ default: MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (throw exceptions).
 */
final class MysqliReportMode
{
    /** @var int Current report mode bitmask. */
    private static int $mode = MysqliConstants::MYSQLI_REPORT_ERROR | MysqliConstants::MYSQLI_REPORT_STRICT;

    public static function getMode(): int
    {
        return self::$mode;
    }

    public static function setMode(int $flags): void
    {
        self::$mode = $flags;
    }

    public static function isStrict(): bool
    {
        return (self::$mode & MysqliConstants::MYSQLI_REPORT_STRICT) !== 0;
    }

    public static function reportsErrors(): bool
    {
        return (self::$mode & MysqliConstants::MYSQLI_REPORT_ERROR) !== 0;
    }
}
