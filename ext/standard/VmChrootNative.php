<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chroot for VM — {@see VmChrootPure} SSOT, no libc FFI (#3500, #12192).
 *
 * JIT/AOT path uses {@see ChrootJitHelper} via {@see \PHPCompiler\JIT\Builtin\StringChroot} (#30558).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chroot)
 */
final class VmChrootNative
{
    public static function available(): bool
    {
        return VmChrootPure::available();
    }

    public static function chroot(string $path): bool
    {
        return VmChrootPure::chroot($path);
    }
}
