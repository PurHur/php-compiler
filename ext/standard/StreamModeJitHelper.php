<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Stream mode registry for compiled JIT/AOT modules (#13021, php-in-PHP).
 *
 * SSOT: {@see VmFs::handleMode()} / {@see VmStreamMeta::userFacingMode()}.
 * php-src: main/streams/streams.c — php_stream_get_meta_data mode field
 */
final class StreamModeJitHelper
{
    public static function register(int $handle, string $mode): void
    {
        if ('' !== $mode) {
            VmFs::registerStreamMode($handle, $mode);
        }
    }

    public static function clear(int $handle): void
    {
        VmFs::clearStreamMode($handle);
    }

    /** @return string|null null when handle has no recorded mode (JIT ABI uses null __string__*) */
    public static function modeForHandle(int $handle): ?string
    {
        if ($handle <= 0) {
            return null;
        }
        $userMode = VmFs::handleMode($handle);
        if (null === $userMode || '' === $userMode) {
            return null;
        }
        $uri = VmFs::handleUri($handle);

        return VmStreamMeta::userFacingMode($uri, $userMode);
    }
}
