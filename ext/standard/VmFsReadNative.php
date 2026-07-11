<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM file reads — {@see VmFsReadPure} SSOT, no libc FFI (#8920, #12315).
 *
 * php-src: ext/standard/file.c — php_stream_copy_to_mem
 * JIT/AOT: __compiler_file_get_contents LLVM lowering (unchanged).
 */
final class VmFsReadNative
{
    public static function available(): bool
    {
        return VmFsReadPure::available();
    }

    public static function read(string $path): string|false
    {
        return VmFsReadPure::read($path);
    }

    /**
     * Read a byte slice from a regular file (php-src ext/standard/file.c offset/length).
     *
     * @return string|false false on open/read failure; '' when offset is past EOF
     */
    public static function readSlice(string $path, int $offset = 0, ?int $length = null): string|false
    {
        return VmFsReadPure::readSlice($path, $offset, $length);
    }
}
