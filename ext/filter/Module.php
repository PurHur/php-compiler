<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ModuleAbstract;

/**
 * filter extension module entry (php-src ext/filter/filter.c; issues #5839, #6028).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new filter_var(),
            new filter_input(),
            new filter_list(),
            new filter_id(),
        ];
    }
}
