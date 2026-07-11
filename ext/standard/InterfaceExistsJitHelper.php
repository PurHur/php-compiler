<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * interface_exists() for compiled JIT/AOT modules (#16185, php-in-PHP).
 *
 * SSOT: {@see VmReflection::interfaceExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(interface_exists)
 */
final class InterfaceExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'InterfaceExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::interfaceExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name),
            true
        );
    }
}
