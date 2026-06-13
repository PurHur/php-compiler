<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc socketpair(2) for stream_socket_pair() without host PHP delegation (#3437).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_socket_pair)
 */
final class VmStreamSocketPairNative
{
    private const AF_UNIX = 1;

    private const AF_INET = 2;

    private const SOCK_STREAM = 1;

    private const SOCK_DGRAM = 2;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{0: resource, 1: resource, 2: int, 3: int}|false
     */
    public static function pair(int $domain, int $type, int $protocol): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $af = self::mapDomain($domain);
        if (null === $af) {
            return false;
        }

        $sockType = self::mapType($type);
        if (null === $sockType) {
            return false;
        }

        if (!self::isSupportedTriple($af, $sockType, $protocol)) {
            return false;
        }

        try {
            $sv = $ffi->new('int[2]');
            $rc = (int) $ffi->socketpair($af, $sockType, $protocol, $sv);
            if (0 !== $rc) {
                return false;
            }

            $fd0 = (int) $sv[0];
            $fd1 = (int) $sv[1];
            $stream0 = self::streamFromFd($ffi, $fd0);
            $stream1 = self::streamFromFd($ffi, $fd1);
            if (false === $stream0 || false === $stream1) {
                if (false !== $stream0) {
                    @fclose($stream0['stream']);
                }
                if (false !== $stream1) {
                    @fclose($stream1['stream']);
                }
                $ffi->close($fd0);
                $ffi->close($fd1);

                return false;
            }

            return [$stream0['stream'], $stream1['stream'], $stream0['fd'], $stream1['fd']];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function mapDomain(int $domain): ?int
    {
        return match ($domain) {
            StdlibConstants::STREAM_PF_UNIX => self::AF_UNIX,
            StdlibConstants::STREAM_PF_INET => self::AF_INET,
            default => null,
        };
    }

    private static function mapType(int $type): ?int
    {
        return match ($type) {
            StdlibConstants::STREAM_SOCK_STREAM => self::SOCK_STREAM,
            StdlibConstants::STREAM_SOCK_DGRAM => self::SOCK_DGRAM,
            default => null,
        };
    }

    private static function isSupportedTriple(int $af, int $sockType, int $protocol): bool
    {
        if (self::AF_UNIX === $af) {
            return true;
        }

        if (self::AF_INET === $af && self::SOCK_STREAM === $sockType) {
            return 0 === $protocol || StdlibConstants::STREAM_IPPROTO_IP === $protocol;
        }

        return false;
    }

    /**
     * @return array{stream: resource, fd: int}|false
     */
    private static function streamFromFd(\FFI $ffi, int $fd): array|false
    {
        $dupFd = (int) $ffi->dup($fd);
        if ($dupFd < 0) {
            return false;
        }

        $stream = @fopen('php://fd/'.$dupFd, 'r+');
        if (false === $stream) {
            $ffi->close($dupFd);

            return false;
        }

        return ['stream' => $stream, 'fd' => $dupFd];
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
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
int socketpair(int domain, int type, int protocol, int sv[2]);
int dup(int oldfd);
int close(int fd);
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
}
