<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * enum_exists() lookup for compiled JIT/AOT modules (#16169, php-in-PHP).
 *
 * VM SSOT: {@see VmReflection::enumExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(enum_exists)
 */
final class EnumExistsJitHelper
{
    public static function exists(string $enumName): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return false;
        }

        return VmReflection::enumExists($ctx, $enumName, true);
    }
}
