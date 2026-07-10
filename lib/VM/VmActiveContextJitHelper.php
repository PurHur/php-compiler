<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Web\Superglobals;

/**
 * Resolve active VM Context for nested JIT/AOT helpers (#17391).
 *
 * VM/MCJIT: Superglobals::$activeContext. Standalone AOT: {@see VmActiveContextLlvm::GLOBAL_NAME}.
 */
final class VmActiveContextJitHelper
{
    public static function resolve(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            return $ctx;
        }

        throw new \LogicException(
            'VmActiveContextJitHelper::resolve() requires an active VM context in this compiler build'
        );
    }
}
