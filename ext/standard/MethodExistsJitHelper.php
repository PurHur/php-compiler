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
    /** 1/0 — NestedJIT `: bool` was i1 ABI with `ret i64 0` (#32701 leftover of #31966). */
    public static function existsArgv(Variable $objectOrClass, string $method): int
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'MethodExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::methodExists($ctx, $objectOrClass, $method) ? 1 : 0;
    }
}
