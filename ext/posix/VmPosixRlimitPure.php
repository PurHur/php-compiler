<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getrlimit() via /proc/self/limits — no libc getrlimit(2) FFI (#12426).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getrlimit)
 * Linux procfs: man 5 proc — /proc/pid/limits
 */
final class VmPosixRlimitPure
{
    /** @var array<string, string> proc limit label → posix_getrlimit key suffix */
    private const LIMIT_NAMES = [
        'Max cpu time' => 'cpu',
        'Max file size' => 'filesize',
        'Max data size' => 'data',
        'Max stack size' => 'stack',
        'Max core file size' => 'core',
        'Max resident set' => 'rss',
        'Max processes' => 'maxproc',
        'Max open files' => 'openfiles',
        'Max locked memory' => 'memlock',
        'Max address space' => 'totalmem',
    ];

    public static function available(): bool
    {
        return null !== self::readProcLimits();
    }

    /**
     * @return array<string, int|string>|null
     */
    public static function getrlimit(): ?array
    {
        $lines = self::readProcLimits();
        if (null === $lines) {
            return null;
        }

        $parsed = [];
        foreach ($lines as $line) {
            if (\str_starts_with($line, 'Limit ')) {
                continue;
            }
            if (!\preg_match(
                '/^(?<label>Max .+?)\\s{2,}(?<soft>\\S+)\\s+(?<hard>\\S+)\\s*(?<units>\\S*)\\s*$/',
                $line,
                $m
            )) {
                continue;
            }
            $key = self::LIMIT_NAMES[$m['label']] ?? null;
            if (null === $key) {
                continue;
            }
            $parsed['soft '.$key] = self::parseLimitToken($m['soft']);
            $parsed['hard '.$key] = self::parseLimitToken($m['hard']);
        }

        if (\count($parsed) !== 20) {
            return null;
        }

        return $parsed;
    }

    public static function setrlimit(int $resource, int $softLimit, int $hardLimit): ?bool
    {
        if (!PosixLibcThinAbi::available()) {
            return null;
        }

        $soft = self::encodeRlimitInput($softLimit);
        $hard = self::encodeRlimitInput($hardLimit);

        return 0 === PosixLibcThinAbi::setrlimit($resource, $soft, $hard);
    }

    public static function lastErrno(): int
    {
        return PosixLibcThinAbi::readErrno();
    }

    private static function encodeRlimitInput(int $value): int
    {
        if (PosixConstants::RLIMIT_INFINITY === $value) {
            return -1;
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private static function readProcLimits(): ?array
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            return null;
        }

        $raw = @\file_get_contents('/proc/self/limits');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $lines = \preg_split('/\\R/', \trim($raw));
        if (!\is_array($lines) || [] === $lines) {
            return null;
        }

        /** @var list<string> $lines */
        return \array_values($lines);
    }

    /** @return int|string php-src prints "unlimited" for RLIM_INFINITY */
    private static function parseLimitToken(string $token): int|string
    {
        if ('unlimited' === \strtolower($token)) {
            return 'unlimited';
        }
        if (!\preg_match('/^-?\\d+$/', $token)) {
            return 'unlimited';
        }

        $value = (int) $token;
        if ($value < 0) {
            return 'unlimited';
        }

        return $value;
    }
}
