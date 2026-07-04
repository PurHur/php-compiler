<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * trait_exists() for compiled JIT/AOT modules (#16173, php-in-PHP).
 *
 * SSOT: {@see VmReflection::traitExists()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(trait_exists)
 */
final class TraitExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'TraitExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::traitExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name),
            true
        );
    }
}
