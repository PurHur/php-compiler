<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\ModuleAbstract;

/**
 * exif extension module entry (php-src ext/exif/exif.c; issue #3400).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new exif_read_data(),
            new exif_imagetype(),
        ];
    }
}
