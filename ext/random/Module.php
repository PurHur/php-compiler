<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * random extension module entry (php-src ext/random/random.c; issue #7102).
 *
 * Randomizer OOP API — Random\Randomizer + Random\Engine\Mt19937 (#13191, #3722).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
