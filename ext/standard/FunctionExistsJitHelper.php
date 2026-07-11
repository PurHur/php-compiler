<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * function_exists() for compiled JIT/AOT modules (#9239, #16424, php-in-PHP).
 *
 * VM SSOT: {@see VmReflection::functionExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(function_exists)
 */
final class FunctionExistsJitHelper
{
    public static function builtinExists(string $name): bool
    {
        $normalized = VmReflection::normalizeGlobalIntrospectionName($name);
        if (!VmReflection::isVisibleToFunctionExists($normalized)) {
            return false;
        }

        return BuiltinRegistry::isAdvertised($normalized);
    }

    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'FunctionExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::functionExists($ctx, $name);
    }
}
