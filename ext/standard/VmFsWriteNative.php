<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM file writes — {@see VmFsWritePure} SSOT, no libc FFI (#8950, #12315).
 *
 * php-src: ext/standard/file.c — php_file_put_contents
 * JIT/AOT: __compiler_file_put_contents LLVM lowering (unchanged).
 */
final class VmFsWriteNative
{
    public static function available(): bool
    {
        return VmFsWritePure::available();
    }

    public static function write(string $path, string $data, int $flags = 0): int|false
    {
        return VmFsWritePure::write($path, $data, $flags);
    }
}
