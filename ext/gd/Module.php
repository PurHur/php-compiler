<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * gd extension module entry (php-src ext/gd/gd.c; issue #7407).
 *
 * libgd drawing parity tracked in #3496; register under {@see standard} so
 * extension_loaded('gd') stays false until libgd ships (#11675).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new imagecreate(),
            new imagecreatetruecolor(),
        ];
    }
}
