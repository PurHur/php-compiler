<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\CompilerVersion;

/**
 * ext/gmp surface advertisement — php-src ext/gmp/gmp.c (#22860, #3341).
 *
 * Pure-PHP GMP ({@see VmGmp}) stays compiled in-tree but is withheld from
 * extension_loaded() / function_exists('gmp_*') / class_exists('GMP') on the
 * reference profile until {@see CompilerVersion::supportsGmp()}.
 * Enable forward profile via {@code PHP_COMPILER_PROFILE=8.4}.
 */
final class GmpExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsGmp();
    }
}
