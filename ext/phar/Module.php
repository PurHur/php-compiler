<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * phar extension module entry (php-src ext/phar/phar.c; issue #3436).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        if (!PharExtensionPolicy::advertisesExtension()) {
            parent::init($runtime);

            return;
        }
        require_once __DIR__.'/bootstrap_pharexception.php';
        BuiltinClasses::register($runtime->vmContext);
        parent::init($runtime);
    }
}
