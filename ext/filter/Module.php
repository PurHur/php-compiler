<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * filter extension module entry (php-src ext/filter/filter.c; issue #5839).
 *
 * Validator bodies remain in ext/standard until #5199 migrates them here.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new filter_var(),
            new filter_list(),
            new filter_id(),
        ];
    }
}
