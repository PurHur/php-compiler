<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * enum_exists() for compiled JIT/AOT modules (#16169, php-in-PHP).
 *
 * SSOT: {@see VmReflection::enumExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(enum_exists)
 */
final class EnumExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'EnumExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::enumExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name),
            true
        );
    }
}
