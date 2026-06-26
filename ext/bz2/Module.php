<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ModuleAbstract;

/**
 * bz2 extension module (php-src ext/bz2/bz2.c; issue #3402, #11840).
 *
 * Register under {@see standard}; advertise logical {@code bz2} extension and
 * bzcompress()/bzdecompress() only when {@see VmBz2Native} can load libbz2 via FFI.
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        return VmBz2Native::available() ? ['bz2'] : [];
    }

    public function getFunctions(): array
    {
        if (!VmBz2Native::available()) {
            return [];
        }

        return [
            new bzcompress(),
            new bzdecompress(),
        ];
    }
}
