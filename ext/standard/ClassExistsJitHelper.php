<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * class_exists() for compiled JIT/AOT modules (#16185, php-in-PHP).
 *
 * SSOT: {@see VmReflection::classExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_exists)
 */
final class ClassExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::classExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name),
            true
        );
    }
}
