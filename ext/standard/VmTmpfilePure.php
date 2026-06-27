<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tmpfile() without host tmpfile() — anonymous plainfile via VmFsTempnamPure (#9033, #12813).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(tmpfile)
 * main/streams/php_stream_temp.c — unlinked temp path + STDIO fd
 */
final class VmTmpfilePure
{
    public static function available(): bool
    {
        return VmPhpFdStream::available() || true;
    }

    /**
     * @return int|false VM stream handle; unlinked plainfile closed on fclose (Zend semantics)
     */
    public static function open(): int|false
    {
        $dir = VmSysGetTempDirNative::resolve();
        $path = VmFsTempnamPure::mkstemp($dir, 'php');
        if (false !== $path) {
            $handle = VmFsOpenNative::open($path, 'r+b');
            VmFsUnlink::unlink($path);
            if (false !== $handle) {
                return $handle;
            }
        }

        return VmPhpMemoryStream::open('php://temp', 'w+b');
    }
}
