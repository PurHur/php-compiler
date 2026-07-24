<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ffi extension module entry (php-src ext/ffi/ffi.c; #4420).
 *
 * VM-only: {@see FFI::cdef}/{@see FFI::new}/{@see cast}/{@see typeof}/{@see sizeof}/
 * {@see addr}/{@see isNull}/{@see free}/{@see memcpy}/{@see memcmp}/{@see memset}/
 * {@see string}/{@see alignof}/{@see type} + dynamic C calls via host {@see \FFI} (libffi).
 * No new runtime/*.c — host FFI is the thin ABI trampoline (#4420, #22369, #22760).
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
