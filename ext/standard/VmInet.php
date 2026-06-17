<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPv4/IPv6 address conversion (issue #3225, #7929).
 *
 * VM: libc FFI via {@see VmInetNative}, then {@see VmInetPure} — no host Zend delegation.
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\InetRuntime}.
 */
final class VmInet
{
    public static function long2ip(int $proper_address): string|false
    {
        if (VmInetNative::available()) {
            $native = VmInetNative::long2ip($proper_address);
            if (false !== $native) {
                return $native;
            }
        }

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
        if (VmInetNative::available()) {
            $native = VmInetNative::inet_ntop($in_addr);
            if (false !== $native) {
                return $native;
            }
        }

        return VmInetPure::inet_ntop($in_addr);
    }

    public static function inet_pton(string $address): string|false
    {
        if (VmInetNative::available()) {
            $native = VmInetNative::inet_pton($address);
            if (false !== $native) {
                return $native;
            }
        }

        return VmInetPure::inet_pton($address);
    }
}
