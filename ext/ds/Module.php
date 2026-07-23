<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ext/ds module — Ds\Vector / Ds\Map / Ds\Set MVP (#22549, php-ds/ext-ds).
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
        return [];
    }
}
