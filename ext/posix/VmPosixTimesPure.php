<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

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

    private static function clockTicksPerSecond(): int
    {
        $v = \getenv('PHP_COMPILER_PROC_CLK_TCK');
        if (false !== $v && '' !== $v) {
            $n = (int) $v;
            if ($n > 0) {
                return $n;
            }
        }

        return 100;
    }

    private static function systemTicks(): int
    {
        $btime = self::readBootTime();
        if (null === $btime) {
            return self::clockTicksPerSecond();
        }
        $elapsed = \time() - $btime;

        return ($elapsed > 0 ? $elapsed : 0) * self::clockTicksPerSecond();
    }

    private static function readBootTime(): ?int
    {
        $raw = @\file_get_contents('/proc/stat');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        if (!\preg_match('/^btime (\\d+)/m', $raw, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
