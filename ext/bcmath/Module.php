<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * bcmath extension module entry (php-src ext/bcmath/bcmath.c; issue #5924).
 *
 * Arithmetic in {@see VmBcmath} (issue #3365 / #5969).
 * Register under {@see standard}; advertise logical {@code bcmath} and bc*()
 * only when {@see CompilerVersion::supportsBcmath()} — withheld on reference profile (#12131).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!CompilerVersion::supportsBcmath()) {
            return [];
        }

        return ['bcmath'];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!CompilerVersion::supportsBcmath()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!CompilerVersion::supportsBcmath()) {
            return [];
        }

        return [
            new bcadd(),
            new bcsub(),
            new bcmul(),
            new bcdiv(),
            new bcdivmod(),
            new bcmod(),
            new bcpow(),
            new bcsqrt(),
            new bcscale(),
            new bccomp(),
            new bcpowmod(),
            new bcceil(),
            new bcfloor(),
            new bcround(),
        ];
    }
}
