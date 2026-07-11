<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unlink(2) for VM — {@see VmFsUnlinkPure} SSOT, no libc FFI (#5063, #7971, #12194).
 *
 * php-src: ext/standard/filestat.c — php_unlink
 * JIT/AOT: ext/standard/JitUnlink.php calls libc unlink(2) directly.
 */
final class VmFsUnlink
{
    public static function unlink(string $path): bool
    {
        return VmFsUnlinkPure::unlink($path);
    }
}
