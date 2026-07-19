<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ffi extension module entry (php-src ext/ffi/ffi.c; #4420).
 *
 * VM-only v1: {@see FFI::cdef} + dynamic C calls via host {@see \FFI} (libffi).
 * No new runtime/*.c — host FFI is the thin ABI trampoline.
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'ffi';
    }

    public function getExtensionVersion(): string
    {
        return '0.1.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!FfiExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [];
    }
}
