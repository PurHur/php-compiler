<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xmlreader extension module entry (php-src ext/xmlreader/php_xmlreader.c; issue #6135).
 *
 * PHP-in-PHP pull parser — no runtime/*.c growth.
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
