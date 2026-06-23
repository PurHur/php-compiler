<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * timezone_location_get() geo metadata for compiled JIT/AOT modules (#9451, php-in-PHP).
 *
 * SSOT: {@see VmDateTimeNative::timezoneLocation()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_location_get)
 */
final class TimezoneLocationJitHelper
{
    public static function locationHashtable(string $tzName): ?HashTable
    {
        $location = VmDateTimeNative::timezoneLocation($tzName);
        if (false === $location) {
            return null;
        }

        $ht = new HashTable();
        foreach ($location as $key => $value) {
            $entry = new Variable();
            if (\is_string($value)) {
                $entry->string($value);
            } elseif (\is_int($value)) {
                $entry->int($value);
            } elseif (\is_float($value)) {
                $entry->float($value);
            } else {
                throw new \LogicException('timezone_location_get() returned unexpected value type');
            }
            $ht->addNew((string) $key, $entry);
        }

        return $ht;
    }
}
