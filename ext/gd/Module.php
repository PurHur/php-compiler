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

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['gd'];
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

        $functions = [
            new imagecreate(),
            new imagecreatetruecolor(),
        ];
        if (GdExtensionPolicy::advertisesDecodeFromString()) {
            $functions[] = new imagecreatefromstring();
            $functions[] = new imagepng();
        }

        return $functions;
    }
}
