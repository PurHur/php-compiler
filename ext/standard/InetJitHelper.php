<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ip2long/long2ip/inet_* for compiled JIT/AOT modules (#8969, php-in-PHP).
 *
 * SSOT: {@see VmInet} / {@see VmInetPure}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 */
final class InetJitHelper
{
    private const TAG_FALSE = 0;

    private const TAG_INT = 1;

    private const TAG_STRING = 2;

    private static int $lastInt = 0;

    private static string $lastString = '';

    /** @return int TAG_FALSE or TAG_INT */
    public static function ip2longTag(string $ip): int
    {
        $result = VmInet::ip2long($ip);
        if (false === $result) {
            return self::TAG_FALSE;
        }
        self::$lastInt = $result;

        return self::TAG_INT;
    }

    public static function lastInt(): int
    {
        return self::$lastInt;
    }

    /** @return int TAG_FALSE or TAG_STRING */
    public static function long2ipTag(int $properAddress): int
    {
        $result = VmInet::long2ip($properAddress);
        if (false === $result) {
            return self::TAG_FALSE;
        }
        self::$lastString = $result;

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString;
    }

    /** @return string|null null when inet_pton() returns false */
    public static function inetPton(string $address): ?string
    {
        $result = VmInet::inet_pton($address);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    /** @return string|null null when inet_ntop() returns false */
    public static function inetNtop(string $inAddr): ?string
    {
        $result = VmInet::inet_ntop($inAddr);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastInt = 0;
        self::$lastString = '';
    }
}
