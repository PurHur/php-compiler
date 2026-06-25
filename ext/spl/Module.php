<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * SPL extension module entry (php-src ext/spl/php_spl.c; issue #4769).
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
            new spl_classes(),
        ];
    }
}
