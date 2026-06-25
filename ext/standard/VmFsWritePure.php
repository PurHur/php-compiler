<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM file writes without libc open/write/flock FFI (#8950, pairs {@see VmFsWriteNative}).
 *
 * Bootstrap path when FFI is disabled: host file_put_contents under Zend VM.
 *
 * php-src: ext/standard/file.c — php_file_put_contents
 */
final class VmFsWritePure
{
    private const FILE_APPEND = 8;

    private const LOCK_EX_FLAG = 2;

    public static function available(): bool
    {
        return \function_exists('file_put_contents');
    }

    public static function write(string $path, string $data, int $flags = 0): int|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        $phpFlags = 0;
        if (0 !== ($flags & self::FILE_APPEND)) {
            $phpFlags |= \FILE_APPEND;
        }
        if (0 !== ($flags & self::LOCK_EX_FLAG)) {
            $phpFlags |= \LOCK_EX;
        }

        $written = @\file_put_contents($path, $data, $phpFlags);
        if (false === $written) {
            return false;
        }

        return $written;
    }
}
