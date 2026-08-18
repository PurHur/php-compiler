<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * property_exists() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmReflection::propertyExists()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(property_exists)
 */
final class PropertyExistsJitHelper
{
    /** 1/0 — NestedJIT `: bool` was i1 ABI with `ret i64 0` (#31966). */
    public static function existsArgv(Variable $objectOrClass, string $property): int
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'PropertyExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::propertyExists($ctx, $objectOrClass, $property) ? 1 : 0;
    }
}
