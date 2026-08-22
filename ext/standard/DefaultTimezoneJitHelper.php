<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * date_default_timezone_get/set for compiled JIT/AOT modules (#9243, #33950).
 *
 * Owns the default-TZ string in this helper TU. Reading {@see VmDate::$defaultTimezone}
 * from NestedJIT under thin AOT SIGSEGVs (split static / #27566). Validation uses
 * zoneinfo {@see is_file} so the helper does not NestedJIT the VmDateTimeNative graph
 * (unbound stubs return false for every id when O=0).
 *
 * Zend/VM still use {@see VmDate}. Free date() civil bake reads VmDate at compile time.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_default_timezone_get/set)
 */
final class DefaultTimezoneJitHelper
{
    private const ZONEINFO_ROOT = '/usr/share/zoneinfo';

    private static string $defaultTimezone = 'UTC';

    public static function defaultTimezoneGet(): string
    {
        return self::$defaultTimezone;
    }

    public static function tryDefaultTimezoneSet(string $timezone): bool
    {
        if ('' === $timezone || '/' === $timezone[0]) {
            return false;
        }
        $len = \strlen($timezone);
        for ($i = 0; $i < $len; ++$i) {
            $c = $timezone[$i];
            if ("\0" === $c) {
                return false;
            }
            if ('.' === $c && $i + 1 < $len && '.' === $timezone[$i + 1]) {
                return false;
            }
        }
        if (self::isWellKnownUtcAlias($timezone)) {
            self::$defaultTimezone = self::canonicalUtcAlias($timezone);

            return true;
        }
        if (self::trySetNumericOffset($timezone)) {
            return true;
        }
        $path = self::ZONEINFO_ROOT.'/'.$timezone;
        if (!\is_file($path)) {
            return false;
        }
        // NestedJIT: static←param aliases the arg — UAF on subsequent get (#33950).
        self::$defaultTimezone = self::copyTimezoneId($timezone);

        return true;
    }

    public static function emitInvalidTimezoneNotice(string $timezone): void
    {
        $message = "date_default_timezone_set(): Timezone ID '{$timezone}' is invalid";
        if (TriggerErrorJitHelper::recordTrigger(ErrorReporter::E_NOTICE, $message, '', 0)) {
            TriggerErrorJitHelper::stderrPrintCliError(ErrorReporter::E_NOTICE, $message, '', 0);
        }
    }

    /** Durable copy for NestedJIT static storage (#33950). */
    private static function copyTimezoneId(string $timezone): string
    {
        $copy = '';
        $len = \strlen($timezone);
        for ($i = 0; $i < $len; ++$i) {
            $copy .= $timezone[$i];
        }

        return $copy;
    }

    /** @return bool true when $timezone was a valid numeric offset and was stored */
    private static function trySetNumericOffset(string $timezone): bool
    {
        $len = \strlen($timezone);
        if ($len < 5 || ('+' !== $timezone[0] && '-' !== $timezone[0])) {
            return false;
        }
        $sign = $timezone[0];
        if (5 === $len) {
            for ($i = 1; $i < 5; ++$i) {
                $c = $timezone[$i];
                if ($c < '0' || $c > '9') {
                    return false;
                }
            }
            $hours = ((int) $timezone[1]) * 10 + (int) $timezone[2];
            $minutes = ((int) $timezone[3]) * 10 + (int) $timezone[4];
        } elseif (6 === $len && ':' === $timezone[3]) {
            for ($i = 1; $i <= 2; ++$i) {
                $c = $timezone[$i];
                if ($c < '0' || $c > '9') {
                    return false;
                }
            }
            for ($i = 4; $i <= 5; ++$i) {
                $c = $timezone[$i];
                if ($c < '0' || $c > '9') {
                    return false;
                }
            }
            $hours = ((int) $timezone[1]) * 10 + (int) $timezone[2];
            $minutes = ((int) $timezone[4]) * 10 + (int) $timezone[5];
        } else {
            return false;
        }
        if ($hours > 18 || $minutes >= 60) {
            return false;
        }
        self::$defaultTimezone = self::copyTimezoneId(
            $sign
            .$timezone[1].$timezone[2]
            .(5 === $len ? $timezone[3].$timezone[4] : $timezone[4].$timezone[5])
        );

        return true;
    }

    private static function isWellKnownUtcAlias(string $timezone): bool
    {
        return 'UTC' === $timezone || 'utc' === $timezone
            || 'GMT' === $timezone || 'gmt' === $timezone
            || 'Z' === $timezone || 'z' === $timezone
            || 'Etc/UTC' === $timezone || 'Etc/utc' === $timezone
            || 'Etc/GMT' === $timezone || 'Etc/gmt' === $timezone;
    }

    private static function canonicalUtcAlias(string $timezone): string
    {
        if ('GMT' === $timezone || 'gmt' === $timezone
            || 'Etc/GMT' === $timezone || 'Etc/gmt' === $timezone) {
            return 'GMT';
        }

        return 'UTC';
    }
}
