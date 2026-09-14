<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/ffi advertisement (php-src ext/ffi/ffi.c; #4420).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
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
        return ExtensionRegistry::advertisesExtensionFor('ffi');
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }
}
