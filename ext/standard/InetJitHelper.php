<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ip2long/long2ip/inet_* NestedJIT helpers (#8969, #27088).
 *
 * Fully inlined IPv4 parse (no private helpers / while / string-eq) for thin AOT.
 */
final class InetJitHelper
{
    private const TAG_FALSE = 0;
    private const TAG_INT = 1;
    private const TAG_STRING = 2;
    private const UINT32_MAX = 4294967295;
    private static int $lastInt = 0;
    private static string $lastString = '';

    public static function ip2longTag(string $ip): int
    {
        $len = \strlen($ip);
        if ($len < 7 || $len > 15) {
            return self::TAG_FALSE;
        }

        $d1 = -1;
        if ($len > 1 && 46 === \ord(\substr($ip, 1, 1))) { $d1 = 1; }
        elseif ($len > 2 && 46 === \ord(\substr($ip, 2, 1))) { $d1 = 2; }
        elseif ($len > 3 && 46 === \ord(\substr($ip, 3, 1))) { $d1 = 3; }
        if (-1 === $d1) { return self::TAG_FALSE; }

        $d2 = -1;
        $b = $d1 + 1;
        if ($b + 1 < $len && 46 === \ord(\substr($ip, $b + 1, 1))) { $d2 = $b + 1; }
        elseif ($b + 2 < $len && 46 === \ord(\substr($ip, $b + 2, 1))) { $d2 = $b + 2; }
        elseif ($b + 3 < $len && 46 === \ord(\substr($ip, $b + 3, 1))) { $d2 = $b + 3; }
        if (-1 === $d2) { return self::TAG_FALSE; }

        $d3 = -1;
        $b = $d2 + 1;
        if ($b + 1 < $len && 46 === \ord(\substr($ip, $b + 1, 1))) { $d3 = $b + 1; }
        elseif ($b + 2 < $len && 46 === \ord(\substr($ip, $b + 2, 1))) { $d3 = $b + 2; }
        elseif ($b + 3 < $len && 46 === \ord(\substr($ip, $b + 3, 1))) { $d3 = $b + 3; }
        if (-1 === $d3) { return self::TAG_FALSE; }

        if ($d3 + 2 < $len && 46 === \ord(\substr($ip, $d3 + 2, 1))) { return self::TAG_FALSE; }
        if ($d3 + 3 < $len && 46 === \ord(\substr($ip, $d3 + 3, 1))) { return self::TAG_FALSE; }
        if ($d3 + 4 < $len && 46 === \ord(\substr($ip, $d3 + 4, 1))) { return self::TAG_FALSE; }

        $__s = \substr($ip, 0, $d1);
        $__n = \strlen($__s);
        $o1 = -1;
        if ($__n >= 1 && $__n <= 3 && !($__n > 1 && 48 === \ord(\substr($__s, 0, 1)))) {
            $__c0 = \ord(\substr($__s, 0, 1));
            if ($__c0 >= 48 && $__c0 <= 57) {
                $__v = $__c0 - 48;
                $__ok = 1;
                if ($__n >= 2) {
                    $__c1 = \ord(\substr($__s, 1, 1));
                    if ($__c1 < 48 || $__c1 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c1 - 48); }
                }
                if (1 === $__ok && $__n >= 3) {
                    $__c2 = \ord(\substr($__s, 2, 1));
                    if ($__c2 < 48 || $__c2 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c2 - 48); }
                }
                if (1 === $__ok && $__v <= 255) { $o1 = $__v; }
            }
        }

        if (-1 === $o1) { return self::TAG_FALSE; }
        $__s = \substr($ip, $d1 + 1, $d2 - $d1 - 1);
        $__n = \strlen($__s);
        $o2 = -1;
        if ($__n >= 1 && $__n <= 3 && !($__n > 1 && 48 === \ord(\substr($__s, 0, 1)))) {
            $__c0 = \ord(\substr($__s, 0, 1));
            if ($__c0 >= 48 && $__c0 <= 57) {
                $__v = $__c0 - 48;
                $__ok = 1;
                if ($__n >= 2) {
                    $__c1 = \ord(\substr($__s, 1, 1));
                    if ($__c1 < 48 || $__c1 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c1 - 48); }
                }
                if (1 === $__ok && $__n >= 3) {
                    $__c2 = \ord(\substr($__s, 2, 1));
                    if ($__c2 < 48 || $__c2 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c2 - 48); }
                }
                if (1 === $__ok && $__v <= 255) { $o2 = $__v; }
            }
        }

        if (-1 === $o2) { return self::TAG_FALSE; }
        $__s = \substr($ip, $d2 + 1, $d3 - $d2 - 1);
        $__n = \strlen($__s);
        $o3 = -1;
        if ($__n >= 1 && $__n <= 3 && !($__n > 1 && 48 === \ord(\substr($__s, 0, 1)))) {
            $__c0 = \ord(\substr($__s, 0, 1));
            if ($__c0 >= 48 && $__c0 <= 57) {
                $__v = $__c0 - 48;
                $__ok = 1;
                if ($__n >= 2) {
                    $__c1 = \ord(\substr($__s, 1, 1));
                    if ($__c1 < 48 || $__c1 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c1 - 48); }
                }
                if (1 === $__ok && $__n >= 3) {
                    $__c2 = \ord(\substr($__s, 2, 1));
                    if ($__c2 < 48 || $__c2 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c2 - 48); }
                }
                if (1 === $__ok && $__v <= 255) { $o3 = $__v; }
            }
        }

        if (-1 === $o3) { return self::TAG_FALSE; }
        $__s = \substr($ip, $d3 + 1);
        $__n = \strlen($__s);
        $o4 = -1;
        if ($__n >= 1 && $__n <= 3 && !($__n > 1 && 48 === \ord(\substr($__s, 0, 1)))) {
            $__c0 = \ord(\substr($__s, 0, 1));
            if ($__c0 >= 48 && $__c0 <= 57) {
                $__v = $__c0 - 48;
                $__ok = 1;
                if ($__n >= 2) {
                    $__c1 = \ord(\substr($__s, 1, 1));
                    if ($__c1 < 48 || $__c1 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c1 - 48); }
                }
                if (1 === $__ok && $__n >= 3) {
                    $__c2 = \ord(\substr($__s, 2, 1));
                    if ($__c2 < 48 || $__c2 > 57) { $__ok = 0; }
                    else { $__v = $__v * 10 + ($__c2 - 48); }
                }
                if (1 === $__ok && $__v <= 255) { $o4 = $__v; }
            }
        }

        if (-1 === $o4) { return self::TAG_FALSE; }

        self::$lastInt = ($o1 << 24) | ($o2 << 16) | ($o3 << 8) | $o4;
        return self::TAG_INT;
    }

    public static function lastInt(): int { return self::$lastInt; }

    public static function long2ipTag(int $properAddress): int
    {
        $properAddress &= self::UINT32_MAX;
        self::$lastString = (($properAddress >> 24) & 0xFF)
            .'.'.(($properAddress >> 16) & 0xFF)
            .'.'.(($properAddress >> 8) & 0xFF)
            .'.'.($properAddress & 0xFF);
        return self::TAG_STRING;
    }

    public static function lastString(): string { return self::$lastString; }

    public static function inetPton(string $address): ?string
    {
        $result = VmInet::inet_pton($address);
        return false === $result ? null : $result;
    }

    public static function inetNtop(string $inAddr): ?string
    {
        $result = VmInet::inet_ntop($inAddr);
        return false === $result ? null : $result;
    }

    public static function resetForTest(): void
    {
        self::$lastInt = 0;
        self::$lastString = '';
    }
}
