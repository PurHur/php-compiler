<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;

/**
 * bz2 extension module (php-src ext/bz2/bz2.c; issue #3402, #11840, #11992).
 *
 * Register under {@see standard}; advertise logical {@code bz2} extension and
 * bzcompress()/bzdecompress() only when {@see CompilerVersion::supportsBz2()} and
 * {@see VmBz2Native} is available (pure PHP via {@see VmBz2Core}) — withheld on reference profile (#11992).
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
        if (!CompilerVersion::supportsBz2() || !VmBz2Native::available()) {
            return [];
        }

        return ['bz2'];
    }

    public function getFunctions(): array
    {
        if (!CompilerVersion::supportsBz2() || !VmBz2Native::available()) {
            return [];
        }

        return [
            new bzcompress(),
            new bzdecompress(),
        ];
    }
}
