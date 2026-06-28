<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP getrusage() compatible shape for VM when libc FFI is disabled (#8970).
 *
 * Linux-only v1: parses /proc/self/stat for a best-effort subset of fields. When /proc is
 * unavailable, returns false (Zend getrusage() is platform-dependent).
 *
 * php-src reference: ext/standard/microtime.c — PHP_FUNCTION(getrusage)
 */
final class VmGetrusagePure
{
    public static function available(): bool
    {
        return null !== self::readProcSelfStat();
    }

    /**
     * @return array<string, int>|false
     */
    public static function getrusage(int $who = 0): array|false
    {
        unset($who);

        $stat = self::readProcSelfStat();
        if (null === $stat) {
            return false;
        }

        // Field map: /proc/[pid]/stat (man 5 proc). $parts[$i] == proc field ($i + 4).
        $minflt = self::intField($stat, 6);
        $majflt = self::intField($stat, 8);
        $utimeTicks = self::intField($stat, 10);
        $stimeTicks = self::intField($stat, 11);
        $rssPages = self::intField($stat, 20);

        [$utimeSec, $utimeUsec] = self::ticksToTimeval($utimeTicks);
        [$stimeSec, $stimeUsec] = self::ticksToTimeval($stimeTicks);

        // ru_maxrss is kilobytes in Linux getrusage(2). rss is pages in /proc.
        $pageSize = self::pageSizeBytes();
        $maxrssKb = (int) max(0, (int) (($rssPages * $pageSize) / 1024));

        return self::toPhpArray([
            'ru_oublock' => 0,
            'ru_inblock' => 0,
            'ru_msgsnd' => 0,
            'ru_msgrcv' => 0,
            'ru_maxrss' => $maxrssKb,
            'ru_ixrss' => 0,
            'ru_idrss' => 0,
            'ru_minflt' => $minflt,
            'ru_majflt' => $majflt,
            'ru_nsignals' => 0,
            'ru_nvcsw' => 0,
            'ru_nivcsw' => 0,
            'ru_nswap' => 0,
            'ru_utime.tv_usec' => $utimeUsec,
            'ru_utime.tv_sec' => $utimeSec,
            'ru_stime.tv_usec' => $stimeUsec,
            'ru_stime.tv_sec' => $stimeSec,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private static function toPhpArray(array $named): array
    {
        $out = [];
        foreach ($named as $key => $value) {
            $out[(string) $key] = (int) $value;
        }

        return $out;
    }

    /**
     * Read and parse /proc/self/stat.
     *
     * @return list<string>|null fields after the "state" token (ppid..)
     */
    private static function readProcSelfStat(): ?array
    {
        $raw = @\file_get_contents('/proc/self/stat');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $raw = \trim($raw);

        // stat format: pid (comm) state rest...
        if (!preg_match('/^(\\d+)\\s+\\((.*)\\)\\s+\\S\\s+(.*)$/', $raw, $m)) {
            return null;
        }

        $rest = $m[3];
        $parts = preg_split('/\\s+/', $rest);
        if (!\is_array($parts) || \count($parts) < 24) {
            return null;
        }

        /** @var list<string> $parts */
        return array_values($parts);
    }

    private static function intField(array $parts, int $idx): int
    {
        $v = $parts[$idx] ?? '0';
        if (!\is_string($v)) {
            return 0;
        }
        // /proc values fit in signed 64-bit for our use; clamp negatives to 0 for shape stability.
        $n = (int) $v;

        return $n < 0 ? 0 : $n;
    }

    /**
     * Convert clock ticks to timeval with best-effort Hz.
     *
     * @return array{0:int,1:int} [sec,usec]
     */
    private static function ticksToTimeval(int $ticks): array
    {
        if ($ticks <= 0) {
            return [0, 0];
        }
        $hz = self::clockTicksPerSecond();
        if ($hz <= 0) {
            $hz = 100;
        }
        $sec = intdiv($ticks, $hz);
        $rem = $ticks - ($sec * $hz);
        $usec = (int) max(0, (int) (($rem * 1000000) / $hz));

        return [$sec, $usec];
    }

    private static function clockTicksPerSecond(): int
    {
        // Best-effort: allow overriding for deterministic tests.
        $v = getenv('PHP_COMPILER_PROC_CLK_TCK');
        if (false !== $v && '' !== $v) {
            $n = (int) $v;
            if ($n > 0) {
                return $n;
            }
        }

        return 100;
    }

    private static function pageSizeBytes(): int
    {
        $v = getenv('PHP_COMPILER_PROC_PAGE_SIZE');
        if (false !== $v && '' !== $v) {
            $n = (int) $v;
            if ($n > 0) {
                return $n;
            }
        }

        return 4096;
    }
}

