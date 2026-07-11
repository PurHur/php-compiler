<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getsid()/posix_getpgid() via procfs — no libc getsid/getpgid FFI (#12673).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getsid), posix_getpgid
 * Linux procfs: man 5 proc — /proc/pid/sessionid, /proc/pid/stat pgrp field
 */
final class VmPosixSessionPure
{
    public static function available(): bool
    {
        return 'Linux' === \PHP_OS_FAMILY && \is_readable('/proc/self/sessionid');
    }

    public static function getsid(int $pid): ?int
    {
        if ($pid < 0) {
            return null;
        }

        $path = 0 === $pid ? '/proc/self/sessionid' : '/proc/'.$pid.'/sessionid';
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $sid = (int) \trim($raw);

        return $sid >= 0 ? $sid : null;
    }

    public static function getpgid(int $pid): ?int
    {
        if ($pid < 0) {
            return null;
        }

        $path = 0 === $pid ? '/proc/self/stat' : '/proc/'.$pid.'/stat';
        $parts = self::readProcStatFields($path);
        if (null === $parts) {
            return null;
        }

        $pgrp = (int) ($parts[1] ?? -1);

        return $pgrp >= 0 ? $pgrp : null;
    }

    public static function setsid(): ?int
    {
        if (!PosixLibcThinAbi::available()) {
            return null;
        }

        return (int) PosixLibcThinAbi::setsid();
    }

    public static function setpgid(int $pid, int $pgid): ?bool
    {
        if (!PosixLibcThinAbi::available()) {
            return null;
        }

        return 0 === PosixLibcThinAbi::setpgid($pid, $pgid);
    }

    public static function lastErrno(): int
    {
        return PosixLibcThinAbi::readErrno();
    }

    /**
     * @return list<string>|null fields after comm: ppid, pgrp, ...
     */
    private static function readProcStatFields(string $path): ?array
    {
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $raw = \trim($raw);
        if (!\preg_match('/^(\\d+)\\s+\\((.*)\\)\\s+\\S\\s+(.*)$/', $raw, $m)) {
            return null;
        }

        $parts = \preg_split('/\\s+/', $m[3]);
        if (!\is_array($parts) || \count($parts) < 2) {
            return null;
        }

        /** @var list<string> $parts */
        return \array_values($parts);
    }
}
