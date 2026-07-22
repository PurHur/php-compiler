<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ModuleAbstract;

/**
 * bz2 extension module (php-src ext/bz2/bz2.c; issue #3402, #11840, #11992).
 *
 * Register under {@see standard}; advertise logical {@code bz2} extension and
 * bzcompress()/bzdecompress() and bzopen/bzread/bzwrite/bzclose/bzerrno/bzerror/bzerrstr/bzflush when
 * {@see Bz2ExtensionPolicy::advertisesExtension()}
 * (pure PHP via {@see VmBz2Core}) — withheld on reference profile (#11992, #14219).
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
        if (!Bz2ExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['bz2'];
    }

    public function getFunctions(): array
    {
        if (!Bz2ExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new bzcompress(),
            new bzdecompress(),
            new bzopen(),
            new bzread(),
            new bzwrite(),
            new bzclose(),
            new bzerrno(),
            new bzerrstr(),
            new bzerror(),
            new bzflush(),
        ];
    }
}
