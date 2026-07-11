<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM fopen on regular paths without libc open/dup FFI (#8950, pairs {@see VmFsOpenNative}).
 *
 * Bootstrap path when FFI is disabled: host fopen under Zend VM, adopted into VmFs handles.
 *
 * php-src: ext/standard/streams.c — _php_stream_fopen
 */
final class VmFsOpenPure
{
    public static function available(): bool
    {
        return \function_exists('fopen');
    }

    /**
     * @return int|false VM stream handle
     */
    public static function open(string $path, string $mode): int|false
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        $phpMode = self::phpStreamMode($mode);
        $fp = @\fopen($path, $phpMode);
        if (false === $fp) {
            return false;
        }

        return VmFs::adoptStreamResource($fp, $path);
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
