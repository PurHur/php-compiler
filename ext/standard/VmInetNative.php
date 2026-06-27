<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPv4/IPv6 address conversion for VM — pure PHP via {@see VmInetPure} (#3225, #8969, #12354).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 * JIT/AOT: lib/JIT/Builtin/InetRuntime.php (__compiler_ip2long, …).
 */
final class VmInetNative
{
    public static function available(): bool
    {
        return true;
    }

    public static function long2ip(int $proper_address): string|false
    {
        return VmInetPure::long2ip($proper_address);
    }

    public static function ip2long(string $ip): int|false
    {
        return VmInetPure::ip2long($ip);
    }

    public static function inet_ntop(string $in_addr): string|false
    {
        return VmInetPure::inet_ntop($in_addr);
    }

    public static function inet_pton(string $address): string|false
    {
        return VmInetPure::inet_pton($address);
    }
}
