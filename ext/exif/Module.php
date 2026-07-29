<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * exif extension module entry (php-src ext/exif/exif.c; issue #3400).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach (ExifConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new exif_read_data(),
            new exif_imagetype(),
            new exif_thumbnail(),
        ];
    }
}
