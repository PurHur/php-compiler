<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php://stdin / php://stdout / php://stderr — CLI standard I/O wrappers (#4648).
 *
 * VM opens via {@see VmFsStdioNative} (libc dup + php://fd/) before host @fopen fallback.
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

    /**
     * @return resource|false
     */
    public static function open(string $uri, string $mode)
    {
        $fd = self::stdioFdForUri($uri);
        if (null === $fd) {
            return false;
        }

        $native = VmFsStdioNative::openDupFd($fd, $mode);
        if (false !== $native) {
            return $native;
        }

        return @fopen($uri, $mode);
    }
}
