<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php://stdin / php://stdout / php://stderr — CLI standard I/O wrappers (#4648).
 *
 * VM opens via {@see VmFsStdioNative} (libc dup + VmPhpFdStream, #8533).
 */
final class VmFsStdio
{
    /** @var array<string, int> */
    private const STDIO_URIS = [
        'php://stdin' => 0,
        'php://stdout' => 1,
        'php://stderr' => 2,
    ];

    public static function stdioFdForUri(string $uri): ?int
    {
        return self::STDIO_URIS[$uri] ?? null;
    }

    public static function isStdioUri(string $uri): bool
    {
        return isset(self::STDIO_URIS[$uri]);
    }

    public static function open(string $uri, string $mode): int|false
    {
        $fd = self::stdioFdForUri($uri);
        if (null === $fd) {
            return false;
        }

        return VmFsStdioNative::openDupFd($fd, $mode);
    }
}
