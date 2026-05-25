<?php

declare(strict_types=1);

/**
 * VM date/time helpers (host libc clock via PHP date/gmdate/time for parity with PHP 8.2).
 */

namespace PHPCompiler\ext\standard;

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
}
