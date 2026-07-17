<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM file writes without libc open/write/flock FFI (#8950, pairs {@see VmFsWriteNative}).
 *
 * Bootstrap / NestedJIT helpers: fopen/fwrite/fclose (not host {@see file_put_contents}) so
 * user-script AOT NestedJIT cannot recurse into `__compiler_file_put_contents` (#20266).
 *
 * php-src: ext/standard/file.c — php_file_put_contents
 */
final class VmFsWritePure
{
    private const FILE_APPEND = 8;

    private const LOCK_EX_FLAG = 2;

    public static function available(): bool
    {
        return \function_exists('fopen') && \function_exists('fwrite') && \function_exists('fclose');
    }

    public static function write(string $path, string $data, int $flags = 0): int|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $path = VmFsLocalPath::resolveAgainstCwd($path);

        $mode = (0 !== ($flags & self::FILE_APPEND)) ? 'ab' : 'wb';
        $fp = @\fopen($path, $mode);
        if (false === $fp) {
            return false;
        }

        if (0 !== ($flags & self::LOCK_EX_FLAG)) {
            if (!@\flock($fp, \LOCK_EX)) {
                @\fclose($fp);

                return false;
            }
        }

        $written = @\fwrite($fp, $data);
        if (0 !== ($flags & self::LOCK_EX_FLAG)) {
            @\flock($fp, \LOCK_UN);
        }
        @\fclose($fp);

        if (false === $written) {
            return false;
        }

        return $written;
    }
}
