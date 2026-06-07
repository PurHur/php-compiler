<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ModuleAbstract;

/**
 * posix extension module entry (php-src ext/posix/posix.c; issue #7105).
 *
 * v1 libc wrappers: #7271; full port tracked in #3339 / #7177.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new posix_getpid(),
            new posix_getppid(),
            new posix_strerror(),
            new posix_get_last_error(),
            new posix_errno(),
        ];
    }
}
