<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

/**
 * ext/ffi advertisement (php-src ext/ffi/ffi.c; #4420).
 *
 * v1 requires host PHP FFI + libffi (same process as the compiler). When the
 * harness lacks ext/ffi, builtins stay unregistered and tests skip.
 */
final class FfiExtensionPolicy
{
    public static function hostFfiAvailable(): bool
    {
        return \extension_loaded('ffi') && \class_exists(\FFI::class, false);
    }

    public static function advertisesExtension(): bool
    {
        return self::hostFfiAvailable();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }
}
