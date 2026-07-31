<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM fopen on regular paths without libc open/dup FFI (#8950, pairs {@see VmFsOpenNative}).
 *
 * Bootstrap path when FFI is disabled: host fopen under Zend VM, adopted into VmFs handles.
 *
 * php-src: main/streams/plain_wrapper.c — php_stream_parse_fopen_modes / _php_stream_fopen
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
        VmFopenMode::clearLastOpenFailureDetail();
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        if (!VmFopenMode::isValid($mode)) {
            VmFopenMode::noteInvalidMode($mode);

            return false;
        }
        $path = VmFsLocalPath::resolveAgainstCwd($path);
        $phpMode = VmFopenMode::phpStreamMode($mode);
        $fp = @\fopen($path, $phpMode);
        if (false === $fp) {
            return false;
        }

        return VmFs::adoptStreamResource($fp, $path);
    }
}
