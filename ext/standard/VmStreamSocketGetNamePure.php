<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_get_name() via /proc — no libc getsockname/getpeername FFI (#12445).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 * Linux procfs: man 5 proc — /proc/pid/fd, /proc/net/tcp, /proc/net/tcp6
 */
final class VmStreamSocketGetNamePure
{
    public static function available(): bool
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            return false;
        }

        return \is_readable('/proc/net/tcp') && VmFsPathPure::available();
    }

    public static function getName(int $handle, bool $wantPeer): string|false
    {
        if (!self::available()) {
            return false;
        }

        $fd = VmPhpFdStream::fdForHandle($handle);
        if (null === $fd) {
            $fd = VmFs::socketFdForHandle($handle);
        }
        if (null === $fd || $fd < 0) {
            return false;
        }

        $inode = self::socketInodeForFd($fd);
        if (null === $inode) {
            return false;
        }

        $v4 = self::lookupProcNet('/proc/net/tcp', $inode, $wantPeer);
        if (false !== $v4) {
            return $v4;
        }

        return self::lookupProcNet('/proc/net/tcp6', $inode, $wantPeer);
    }

    private static function socketInodeForFd(int $fd): ?int
    {
        $target = VmFsPathPure::readlink('/proc/self/fd/'.$fd);
        if (false === $target || !\str_starts_with($target, 'socket:[')) {
            return null;
        }
        if (!\preg_match('/^socket:\[(?<inode>\d+)\]$/', $target, $m)) {
            return null;
        }

        return (int) $m['inode'];
    }

    private static function lookupProcNet(string $path, int $inode, bool $wantPeer): string|false
    {
        $raw = @\file_get_contents($path);
        if (false === $raw) {
            return false;
        }

        foreach (\explode("\n", $raw) as $line) {
            $line = \trim($line);
            if ('' === $line || \str_starts_with($line, 'sl')) {
                continue;
            }
            $fields = \preg_split('/\s+/', $line);
            if (!\is_array($fields) || \count($fields) < 10) {
                continue;
            }
            if ((int) $fields[9] !== $inode) {
                continue;
            }

            $addrField = $wantPeer ? $fields[2] : $fields[1];
            if ($wantPeer && ('00000000:0000' === $addrField || '00000000000000000000000000000000:0000' === $addrField)) {
                return false;
            }

            return self::formatProcNetAddress($addrField, \str_contains($path, 'tcp6'));
        }

        return false;
    }

    private static function formatProcNetAddress(string $field, bool $ipv6): string|false
    {
        $parts = \explode(':', $field, 2);
        if (2 !== \count($parts)) {
            return false;
        }
        [$ipHex, $portHex] = $parts;
        $port = (int) \hexdec($portHex);
        if ($port < 0 || $port > 65535) {
            return false;
        }

        if ($ipv6) {
            if (32 !== \strlen($ipHex)) {
                return false;
            }
            $packed = \pack('H*', $ipHex);
            if (16 !== \strlen($packed)) {
                return false;
            }
            $host = \inet_ntop($packed);
            if (false === $host) {
                return false;
            }

            return '['.$host.']:'.$port;
        }

        if (8 !== \strlen($ipHex)) {
            return false;
        }
        $packed = \pack('H*', $ipHex);
        if (4 !== \strlen($packed)) {
            return false;
        }
        $host = \inet_ntop(\strrev($packed));
        if (false === $host) {
            return false;
        }

        return $host.':'.$port;
    }
}
