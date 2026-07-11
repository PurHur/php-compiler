<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php://stdin|stdout|stderr without libc dup(2) FFI (#12252, pairs #4648).
 *
 * Bootstrap path: host fopen on stdio URIs under Zend VM, adopted into VmFs handles.
 *
 * php-src: ext/standard/streams.c — php_stream_stdio_ops
 */
final class VmFsStdioPure
{
    /** @var array<string, int> */
    private const STDIO_URIS = [
        'php://stdin' => 0,
        'php://stdout' => 1,
        'php://stderr' => 2,
    ];

    public static function available(): bool
    {
        return \function_exists('fopen');
    }

    /**
     * @return int|false VM fd stream handle
     */
    public static function openDupFd(int $fd, string $mode): int|false
    {
        if ($fd < 0 || $fd > 2) {
            return false;
        }
        if (!self::available()) {
            return false;
        }

        $uri = array_search($fd, self::STDIO_URIS, true);
        if (false === $uri) {
            return false;
        }

        $phpMode = self::phpStreamMode($mode);
        $fp = @\fopen($uri, $phpMode);
        if (false === $fp) {
            return false;
        }

        return VmFs::adoptStreamResource($fp, $uri);
    }

    private static function phpStreamMode(string $mode): string
    {
        if ('' === $mode) {
            return 'rb';
        }
        if (!str_contains($mode, 'b')) {
            return $mode.'b';
        }

        return $mode;
    }
}
