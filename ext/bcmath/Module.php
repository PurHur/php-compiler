<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * bcmath extension module entry (php-src ext/bcmath/bcmath.c; issue #5924).
 *
 * Arithmetic in {@see VmBcmath} (issue #3365 / #5969).
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
            new bcadd(),
            new bcsub(),
            new bcmul(),
            new bcdiv(),
            new bcdivmod(),
            new bcscale(),
            new bccomp(),
            new bcpowmod(),
            new bcceil(),
            new bcfloor(),
        ];
    }
}
