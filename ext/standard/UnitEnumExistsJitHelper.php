<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * unitenum_exists() for compiled JIT/AOT modules (#16169, php-in-PHP).
 *
 * SSOT: {@see VmReflection::unitEnumExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(unitenum_exists)
 */
final class UnitEnumExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'UnitEnumExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::unitEnumExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name)
        );
    }
}
