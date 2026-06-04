<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * mbstring extension module entry (php-src ext/mbstring/mbstring.c; issue #5695).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new mb_strlen(),
        ];
    }
}
