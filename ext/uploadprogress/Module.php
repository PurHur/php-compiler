<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

use PHPCompiler\ModuleAbstract;

/**
 * uploadprogress extension module entry (PECL ext/uploadprogress; issue #6386).
 *
 * PHP-in-PHP builtins; no runtime/*.c.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new uploadprogress_get_info(),
            new uploadprogress_get_contents(),
        ];
    }
}
