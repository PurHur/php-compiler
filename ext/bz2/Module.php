<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ModuleAbstract;

/**
 * bz2 extension module (php-src ext/bz2/bz2.c; issue #3402).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new bzcompress(),
            new bzdecompress(),
        ];
    }
}
