<?php

declare(strict_types=1);

/**
 * VM date/time helpers (host libc clock via PHP date/gmdate/time for parity with PHP 8.2).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

final class VmDate
{
    public static function time(): int
    {
        return (int) \time();
    }

    public static function getmypid(): int
    {
        return (int) \getmypid();
    }

    public static function date(string $format, ?int $timestamp = null): string
    {
        return \date($format, $timestamp ?? self::time());
    }

    public static function gmdate(string $format, ?int $timestamp = null): string
    {
        return \gmdate($format, $timestamp ?? self::time());
    }

    /** @return string|float */
    public static function microtime(bool $asFloat = false)
    {
        return \microtime($asFloat);
    }

    public static function getdate(?int $timestamp = null): HashTable
    {
        $raw = \getdate($timestamp ?? self::time());
        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }
}
