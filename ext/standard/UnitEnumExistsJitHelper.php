<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * unitenum_exists() lookup for compiled JIT/AOT modules (#16169, php-in-PHP).
 *
 * VM SSOT: {@see VmReflection::unitEnumExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(unitenum_exists)
 */
final class UnitEnumExistsJitHelper
{
    public static function exists(string $enumName): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return false;
        }

        return VmReflection::unitEnumExists($ctx, $enumName);
    }
}
