<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * method_exists() for compiled JIT/AOT modules (#16479, php-in-PHP).
 *
 * SSOT: {@see VmReflection::methodExists()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(method_exists)
 */
final class MethodExistsJitHelper
{
    public static function existsArgv(Variable $objectOrClass, string $method): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'MethodExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::methodExists($ctx, $objectOrClass, $method);
    }
}
