<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPv4/IPv6 address conversion (issue #3225, #7929, #8969).
 *
 * VM: pure PHP via {@see VmInetPure} by default; optional libc FFI when PHP_COMPILER_INET_FFI=1.
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\InetRuntime} → {@see InetJitHelper}.
 */
final class VmInet
{
    public static function long2ip(int $proper_address): string|false
    {
        return VmInetPure::long2ip($proper_address);
    }

    public static function ip2long(string $ip): int|false
    {
        // php-src php_ip2long() rejects leading-zero octets; libc inet_aton does not (#9300).
        return VmInetPure::ip2long($ip);
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

        return VmInetPure::inet_ntop($in_addr);
    }

    public static function inet_pton(string $address): string|false
    {
        return VmInetPure::inet_pton($address);
    }
}
