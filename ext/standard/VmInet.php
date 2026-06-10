<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPv4/IPv6 address conversion (issue #3225).
 *
 * VM: libc FFI via {@see VmInetNative} when available; host Zend fallback for dev only.
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\InetRuntime}.
 */
final class VmInet
{
    public static function long2ip(int $proper_address): string|false
    {
        if ($proper_address < 0 || $proper_address > 4294967295) {
            return false;
        }
        if (VmInetNative::available()) {
            $native = VmInetNative::long2ip($proper_address);
            if (false !== $native) {
                return $native;
            }
        }

        return \long2ip($proper_address);
    }

    public static function ip2long(string $ip): int|false
    {
        if (VmInetNative::available()) {
            $native = VmInetNative::ip2long($ip);
            if (false !== $native) {
                return $native;
            }
        }

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
        if (VmInetNative::available()) {
            $native = VmInetNative::inet_ntop($in_addr);
            if (false !== $native) {
                return $native;
            }
        }

        $result = \inet_ntop($in_addr);

        return false === $result ? false : $result;
    }

    public static function inet_pton(string $address): string|false
    {
        if (VmInetNative::available()) {
            $native = VmInetNative::inet_pton($address);
            if (false !== $native) {
                return $native;
            }
        }

        $result = \inet_pton($address);

        return false === $result ? false : $result;
    }
}
