<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_get_name() — socket address formatting (issue #12223).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 */
final class VmStreamSocketGetName
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const AF_UNIX = 1;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function getName(int $handle, bool $wantPeer): string|false
    {
        $fd = VmPhpFdStream::fdForHandle($handle);
        if (null === $fd) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $buf = $ffi->new('char[128]');
            $len = $ffi->new('unsigned int');
            $len->cdata = 128;
            $rc = $wantPeer
                ? (int) $ffi->getpeername($fd, \FFI::addr($buf), \FFI::addr($len))
                : (int) $ffi->getsockname($fd, \FFI::addr($buf), \FFI::addr($len));
            if (0 !== $rc) {
                return false;
            }

            return self::formatSockaddrRaw(\FFI::string($buf, (int) $len->cdata));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function formatSockaddrRaw(string $raw): string|false
    {
        if ('' === $raw) {
            return false;
        }
        $family = \unpack('v', $raw)[1] ?? 0;
        if (self::AF_INET === $family) {
            if (\strlen($raw) < 8) {
                return false;
            }
            $port = \unpack('n', \substr($raw, 2, 2))[1] ?? 0;
            $addr = \substr($raw, 4, 4);
            if (4 !== \strlen($addr)) {
                return false;
            }
            $host = \inet_ntop($addr);
            if (false === $host) {
                return false;
            }

            return $host.':'.$port;
        }
        if (self::AF_INET6 === $family) {
            if (\strlen($raw) < 28) {
                return false;
            }
            $port = \unpack('n', \substr($raw, 2, 2))[1] ?? 0;
            $addr = \substr($raw, 8, 16);
            if (16 !== \strlen($addr)) {
                return false;
            }
            $host = \inet_ntop($addr);
            if (false === $host) {
                return false;
            }

            return '['.$host.']:'.$port;
        }
        if (self::AF_UNIX === $family) {
            $path = \rtrim(\substr($raw, 2), "\0");
            if ('' === $path) {
                return false;
            }

            return $path;
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
typedef unsigned int socklen_t;
int getsockname(int sockfd, void *addr, socklen_t *addrlen);
int getpeername(int sockfd, void *addr, socklen_t *addrlen);
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
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
