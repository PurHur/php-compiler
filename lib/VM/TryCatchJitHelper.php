<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Web\Superglobals;

/**
 * Catch-arm type matching for compiled JIT/AOT modules (#16247, php-in-PHP).
 *
 * SSOT: {@see VmTryCatch}
 * php-src: Zend/zend_exceptions.c — caught class / interface checks
 */
final class TryCatchJitHelper
{
    public static function encodedTypesMatchArgv(string $thrownClassLc, string $encodedTypes): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'TryCatchJitHelper::encodedTypesMatchArgv() requires an active VM context in this compiler build'
            );
        }

        return VmTryCatch::encodedTypesMatchClassName($encodedTypes, $thrownClassLc, $ctx);
    }
}
