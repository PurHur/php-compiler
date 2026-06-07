<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * filter extension module entry (php-src ext/filter/filter.c; issue #5839).
 *
 * FILTER_VALIDATE_EMAIL LLVM lives in lib/JIT/Builtin/StringFilterEmail.php (#5199);
 * FILTER_VALIDATE_INT stays in ext/standard/VmFilter.php until relocated here.
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
