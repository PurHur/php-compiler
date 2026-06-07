<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * gd extension module entry (php-src ext/gd/gd.c; issue #7407).
 *
 * libgd drawing parity tracked in #3496; v1 skeleton enables function_exists() and inventory.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [
            new imagecreate(),
            new imagecreatetruecolor(),
        ];
    }
}
