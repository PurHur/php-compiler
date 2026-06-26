<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tmpfile() without host PHP — anonymous plainfile via mkstemp(3) (#9033, #11397).
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
        if (VmPhpFdStream::available()) {
            $opened = VmFsTempnamNative::mkstempOpen(VmSysGetTempDirNative::resolve(), 'php');
            if (false !== $opened) {
                [$fd, $path] = $opened;
                VmFsTempnamNative::unlinkPath($path);
                $handle = VmPhpFdStream::adopt($fd, $path, 'r+b');
                if (false !== $handle) {
                    return $handle;
                }
            }
        }

        return VmPhpMemoryStream::open('php://temp', 'w+b');
    }
}
