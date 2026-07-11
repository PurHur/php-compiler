<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chroot for VM — {@see VmChrootPure} SSOT, no libc FFI (#3500, #12192).
 *
 * Mirrors {@see JitChroot} — libc chroot only on JIT/AOT path.
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
