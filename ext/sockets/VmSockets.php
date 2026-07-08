<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\VmFsPathNative;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;

/**
 * VM helpers for ext/sockets builtins (php-src ext/sockets/sockets.c; #6544, #6203).
 *
 * Uses libc sockatmark(3) via FFI only — no host Zend socket_atmark() delegation (#8176).
 */
final class VmSockets
{
    private static \FFI|false|null $ffi = null;

    public static function isAtmarkSupported(): bool
    {
        return null !== self::ffi();
    }

    public static function atmarkForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::atmarkForFd($fd);
    }

    public static function atmarkForFd(int $fd): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $r = (int) $ffi->sockatmark($fd);

        return $r >= 0 && 0 !== $r;
    }

    /** php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_nonblock) via fcntl(F_SETFL). */
    public static function setNonblockForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::setNonblockForFd($fd);
    }

    /** php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_block) via fcntl(F_SETFL). */
    public static function setBlockForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::setBlockForFd($fd);
    }

    public static function setNonblockForFd(int $fd): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $flags = (int) $ffi->fcntl($fd, self::F_GETFL, 0);
            if (-1 === $flags) {
                return false;
            }

            return -1 !== (int) $ffi->fcntl($fd, self::F_SETFL, $flags | self::O_NONBLOCK);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setBlockForFd(int $fd): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $flags = (int) $ffi->fcntl($fd, self::F_GETFL, 0);
            if (-1 === $flags) {
                return false;
            }

            return -1 !== (int) $ffi->fcntl($fd, self::F_SETFL, $flags & ~self::O_NONBLOCK);
        } catch (\Throwable) {
            return false;
        }
    }

    private const F_GETFL = 3;
    private const F_SETFL = 4;
    private const O_NONBLOCK = 2048;

    /** @return list<int> */
    public static function discoverNewSocketFds(array $beforeSockets): array
    {
        $new = [];
        foreach (self::enumerateSocketFds() as $fd => $_target) {
            if (!isset($beforeSockets[$fd])) {
                $new[] = $fd;
            }
        }
        sort($new);

        return $new;
    }

    public static function discoverNewSocketFd(array $beforeSockets): ?int
    {
        $new = self::discoverNewSocketFds($beforeSockets);

        return $new[0] ?? null;
    }

    public static function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
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
                    int getsockname(int sockfd, void *addr, unsigned int *addrlen);
                    int fcntl(int fd, int cmd, ...);',
                    $lib
                );

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffi = false;

        return null;
    }

    /** Exposed for {@see VmSocket::fdForObject()}. */
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
            $target = VmFsPathNative::readlink($path);
            if (false === $target || !str_starts_with($target, 'socket:')) {
                continue;
            }
            $out[(int) basename($path)] = $target;
        }

        return $out;
    }
}
