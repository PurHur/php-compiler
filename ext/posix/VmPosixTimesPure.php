<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\VmProcClockTicksPure;

/**
 * posix_times() via /proc/self/stat — no libc times(2) FFI (#12411).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_times)
 * Linux procfs: man 5 proc — utime/stime/cutime/cstime fields (clock ticks).
 */
final class VmPosixTimesPure
{
    public static function available(): bool
    {
        return null !== self::readProcSelfStat();
    }

    /**
     * @return array{ticks: int, utime: int, stime: int, cutime: int, cstime: int}|null
     */
    public static function times(): ?array
    {
        $stat = self::readProcSelfStat();
        if (null === $stat) {
            return null;
        }

        return [
            'ticks' => self::systemTicks(),
            'utime' => self::intField($stat, 10),
            'stime' => self::intField($stat, 11),
            'cutime' => self::intField($stat, 12),
            'cstime' => self::intField($stat, 13),
        ];
    }

    /**
     * @return list<string>|null fields after the "state" token (ppid..)
     */
    private static function readProcSelfStat(): ?array
    {
        $raw = @\file_get_contents('/proc/self/stat');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $raw = \trim($raw);
        if (!\preg_match('/^(\\d+)\\s+\\((.*)\\)\\s+\\S\\s+(.*)$/', $raw, $m)) {
            return null;
        }

        $parts = \preg_split('/\\s+/', $m[3]);
        if (!\is_array($parts) || \count($parts) < 14) {
            return null;
        }

        /** @var list<string> $parts */
        return \array_values($parts);
    }

    /**
     * @param list<string> $parts
     */
    private static function intField(array $parts, int $idx): int
    {
        $v = $parts[$idx] ?? '0';
        if (!\is_string($v)) {
            return 0;
        }
        $n = (int) $v;

        return $n < 0 ? 0 : $n;
    }

    private static function systemTicks(): int
    {
        $fromLibc = PosixLibcThinAbi::systemClockTicks();
        if (null !== $fromLibc && $fromLibc > 0) {
            return $fromLibc;
        }

        $uptime = self::readUptimeSeconds();
        if (null === $uptime) {
            return VmProcClockTicksPure::clockTicksPerSecond();
        }

        return (int) \round($uptime * VmProcClockTicksPure::clockTicksPerSecond());
    }

    private static function readUptimeSeconds(): ?float
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            return null;
        }

        $raw = @\file_get_contents('/proc/uptime');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $space = \strpos($raw, ' ');
        if (false === $space) {
            return null;
        }

        $secs = (float) \substr($raw, 0, $space);

        return $secs >= 0.0 ? $secs : null;
    }
}
