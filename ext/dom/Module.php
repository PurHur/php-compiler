<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * dom extension module entry (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP DOM factory — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
