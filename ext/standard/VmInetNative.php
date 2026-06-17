<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc inet_aton/inet_pton/inet_ntop for VM without host PHP inet builtin delegation (#3225 VM phase).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 * JIT/AOT: lib/JIT/Builtin/InetRuntime.php (__compiler_ip2long, …).
 */
final class VmInetNative
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const INET6_ADDRSTRLEN = 46;

    private const UINT32_MAX = 4294967295;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return self::ffiEnabled() && null !== self::ffi();
    }

    public static function long2ip(int $proper_address): string|false
    {
        if ($proper_address < 0 || $proper_address > self::UINT32_MAX) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmInetPure::long2ip($proper_address);
        }

        try {
            $in = $ffi->new('struct in_addr');
            $in->s_addr = $ffi->htonl((int) ($proper_address & 0xFFFFFFFF));
            $ptr = $ffi->inet_ntoa($in);

            return \FFI::string($ptr);
        } catch (\Throwable) {
            return VmInetPure::long2ip($proper_address);
        }
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
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmInetPure::inet_ntop($in_addr);
        }

        try {
            $af = 4 === $len ? self::AF_INET : self::AF_INET6;
            $src = $ffi->new('unsigned char['.$len.']');
            for ($i = 0; $i < $len; ++$i) {
                $src[$i] = \ord($in_addr[$i]);
            }
            $dst = $ffi->new('char['.self::INET6_ADDRSTRLEN.']');
            $ptr = $ffi->inet_ntop(
                $af,
                \FFI::addr($src[0]),
                \FFI::addr($dst[0]),
                self::INET6_ADDRSTRLEN
            );

            return self::coerceNtopResult($ptr);
        } catch (\Throwable) {
            return VmInetPure::inet_ntop($in_addr);
        }
    }

    public static function inet_pton(string $address): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmInetPure::inet_pton($address);
        }

        try {
            $buf4 = $ffi->new('unsigned char[4]');
            if (1 === (int) $ffi->inet_pton(self::AF_INET, $address, \FFI::addr($buf4[0]))) {
                return \FFI::string($buf4, 4);
            }
            $buf16 = $ffi->new('unsigned char[16]');
            if (1 === (int) $ffi->inet_pton(self::AF_INET6, $address, \FFI::addr($buf16[0]))) {
                return \FFI::string($buf16, 16);
            }

            return VmInetPure::inet_pton($address);
        } catch (\Throwable) {
            return VmInetPure::inet_pton($address);
        }
    }

    /** @param mixed $ptr libc inet_ntop return (PHP FFI may coerce const char* to string). */
    private static function coerceNtopResult(mixed $ptr): string|false
    {
        if (null === $ptr || false === $ptr) {
            return false;
        }
        if (\is_string($ptr)) {
            return '' === $ptr ? false : $ptr;
        }
        if ($ptr instanceof \FFI\CData) {
            return \FFI::string($ptr);
        }

        return false;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned int uint32_t;
struct in_addr {
    uint32_t s_addr;
};
int inet_aton(const char *cp, struct in_addr *inp);
char *inet_ntoa(struct in_addr in);
int inet_pton(int af, const char *src, void *dst);
const char *inet_ntop(int af, const void *src, char *dst, unsigned int size);
uint32_t ntohl(uint32_t netlong);
uint32_t htonl(uint32_t hostlong);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
