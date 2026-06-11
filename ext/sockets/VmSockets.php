<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * VM helpers for ext/sockets builtins (php-src ext/sockets/sockets.c; #6544).
 *
 * Uses libc sockatmark(3) via FFI when host PHP lacks HAVE_SOCKATMARK; no runtime/*.c.
 */
final class VmSockets
{
    private static \FFI|false|null $ffi = null;

    public static function isAtmarkSupported(): bool
    {
        return null !== self::ffi();
    }

    public static function atmark(\Socket $socket): bool
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fd = VmSocket::fdForHostSocket($socket);
            if (null !== $fd) {
                $r = (int) $ffi->sockatmark($fd);
                if ($r >= 0) {
                    return 0 !== $r;
                }
            }
        }
        if (\function_exists('socket_atmark')) {
            return \socket_atmark($socket);
        }

        return false;
    }

    private static function ffi(): ?\FFI
    {
        if (false === self::$ffi) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            self::$ffi = false;

            return null;
        }
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef(
                    'int sockatmark(int sockfd);
                    int getsockname(int sockfd, void *addr, unsigned int *addrlen);',
                    $lib
                );

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffi = false;

        return null;
    }

    /** Exposed for {@see VmSocket::fdForHostSocket()}. */
    public static function getsocknameFd(int $fd, string &$addr): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $buf = $ffi->new('char[108]');
        $len = $ffi->new('unsigned int');
        $len->cdata = 108;
        if (0 !== (int) $ffi->getsockname($fd, \FFI::addr($buf), \FFI::addr($len))) {
            return false;
        }
        $addr = \FFI::string($buf, (int) $len->cdata);

        return true;
    }

    /**
     * @return array<int, string> fd => socket:[inode]
     */
    public static function enumerateSocketFds(): array
    {
        $out = [];
        foreach (glob('/proc/self/fd/*') ?: [] as $path) {
            $target = @readlink($path);
            if (!\is_string($target) || !str_starts_with($target, 'socket:')) {
                continue;
            }
            $out[(int) basename($path)] = $target;
        }

        return $out;
    }
}
