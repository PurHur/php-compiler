<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT pending HTTP header dispatch via PendingHeadersJitHelper PHP (#9545, #20930, #33255).
 *
 * Owns `__phpc_pending_header_*` / `__phpc_header_queue_enable` /
 * `__phpc_response_headers_flush` / `__phpc_setcookie_add` module-locally via
 * {@see PendingHeadersJitBridge} (`getNamedFunction` first). Do not re-add empty
 * always-on shells in {@see Type} — leftover decls mint pending_header_*.1
 * (#31894 / #32122 / #33255).
 *
 * Embed and thin standalone AOT both NestedJIT via {@see PendingHeadersJitBridge}
 * (IncludePath #20877 shape — no thin stub fork).
 * php-src: ext/standard/head.c
 */
final class PendingHeadersRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Thin AOT: fill Type::register empty pending-header ABI shells for link (#20932). */
    public static function ensureThinAotLinkStubs(Context $context): void
    {
        PendingHeadersJitBridge::fillThinAotLinkStubs($context);
    }

    public static function implement(Context $context): void
    {
        PendingHeadersJitBridge::implement($context);
    }
}
