<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Stream path registry for compiled JIT/AOT modules (#9480, php-in-PHP).
 *
 * SSOT: {@see VmFs::handleUri()} / {@see VmFs::registerStreamPath()}.
 * php-src: ext/standard/streams.c — php_stream path/url metadata
 */
final class StreamPathJitHelper
{
    public static function register(int $handle, string $path): void
    {
        if ('' !== $path) {
            VmFs::registerStreamPath($handle, $path);
        }
    }

    public static function clear(int $handle): void
    {
        VmFs::clearStreamPath($handle);
    }

    /** @return string|null null when handle has no recorded path (JIT ABI uses null __string__*) */
    public static function pathForHandle(int $handle): ?string
    {
        if ($handle <= 0) {
            return null;
        }
        $uri = VmFs::handleUri($handle);

        return '' === $uri ? null : $uri;
    }
}
