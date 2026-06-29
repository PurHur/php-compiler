<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT pending HTTP header dispatch via PendingHeadersJitHelper PHP (#9545, #13679).
 *
 * Embed and standalone both route through {@see PendingHeadersJitBridge}.
 * User-script AOT uses deferred inventory stubs (no nested JIT) per #13571.
 * php-src: ext/standard/head.c
 */
final class PendingHeadersRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        PendingHeadersJitBridge::implement($context);
    }
}
