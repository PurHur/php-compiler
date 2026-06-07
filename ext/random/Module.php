<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * random extension module entry (php-src ext/random/random.c; issue #7102).
 *
 * Randomizer behavior tracked in #3722; v1 skeleton enables class_exists() and inventory.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
