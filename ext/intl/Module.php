<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * intl extension module entry (php-src ext/intl/php_intl.c; issue #5774).
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
            new intl_get_error_code(),
        ];
    }
}
