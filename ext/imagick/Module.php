<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * imagick extension module entry (php-src ext/imagick/imagick.c; #6235).
 *
 * PHP-in-PHP Imagick with ImageMagick CLI backend when pecl-imagick is absent.
 * VM-only v1; JIT/AOT follow when hot paths identified.
 */
class Module extends ModuleAbstract
{
    private const IMAGICK_VERSION = '3.7.0';

    public function getExtensionName(): string
    {
        return 'imagick';
    }

    public function getExtensionVersion(): string
    {
        return self::IMAGICK_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!ImagickExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmImagick::registerClass($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [];
    }
}
