<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPv4/IPv6 address conversion (issue #3225).
 *
 * VM delegates to Zend host builtins when available (php-src ext/standard/basic_functions.c).
 */
final class VmInet
{
    public static function long2ip(int $proper_address): string|false
    {
        if ($proper_address < 0 || $proper_address > 4294967295) {
            return false;
        }

        return \long2ip($proper_address);
    }

    public static function ip2long(string $ip): int|false
    {
        $result = \ip2long($ip);

        return false === $result ? false : (int) $result;
    }

    public static function inet_ntop(string $in_addr): string|false
    {
        if ('' === $in_addr) {
            return false;
        }
        $len = VmString::byteLength($in_addr);
        if (4 !== $len && 16 !== $len) {
            return false;
        }
        $result = \inet_ntop($in_addr);

        return false === $result ? false : $result;
    }

    public static function inet_pton(string $address): string|false
    {
        $result = \inet_pton($address);

        return false === $result ? false : $result;
    }
}
