<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT pending HTTP header dispatch via PendingHeadersJitHelper PHP (#9545, #12898).
 *
 * JIT embed and AOT standalone compile {@see PendingHeadersJitBridge}; thin LLVM bridges forward the ABI.
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
