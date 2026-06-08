<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc tmpfile(3) for VM without host \tmpfile() (issue #4929, #3228).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(tmpfile)
 * JIT/AOT: {@see JitTmpfile} / __compiler_tmpfile via StreamIoJit.
 */
final class VmTmpfileNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /**
     * @return resource|false PHP stream open for read/write; unlinked on fclose (Zend semantics)
     */
    public static function open()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $libcFp = $ffi->tmpfile();
            if (null === $libcFp) {
                return false;
            }

            $fd = (int) $ffi->fileno($libcFp);
            if ($fd < 0) {
                $ffi->fclose($libcFp);

                return false;
            }

            $dupFd = (int) $ffi->dup($fd);
            if ($dupFd < 0) {
                $ffi->fclose($libcFp);

                return false;
            }

            // Close libc FILE*; dup keeps the anonymous temp file alive until stream fclose.
            $ffi->fclose($libcFp);

            $stream = @fopen('php://fd/'.$dupFd, 'w+b');
            if (false === $stream) {
                $ffi->close($dupFd);

                return false;
            }

            return $stream;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct _IO_FILE FILE;
FILE *tmpfile(void);
int fileno(FILE *stream);
int fclose(FILE *stream);
int dup(int oldfd);
int close(int fd);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
