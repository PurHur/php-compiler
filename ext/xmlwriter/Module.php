<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xmlwriter extension module entry (php-src ext/xmlwriter/php_xmlwriter.c; issue #6065).
 *
 * PHP-in-PHP streaming writer — no runtime/*.c growth.
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
