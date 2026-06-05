<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for network service lookups (issue #5333).
 *
 * Replaces lib/AOT/runtime/phpc_network_services.c via StringNetworkServicesJit.
 */
final class StringNetworkServices
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        StringNetworkServicesJit::implement($context);
    }
}
