<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT pending HTTP header dispatch via PendingHeadersJitHelper PHP (#9545, #20930, #33255).
 *
 * Owns `__phpc_pending_header_*` / `__phpc_header_queue_enable` /
 * `__phpc_response_headers_flush` / `__phpc_setcookie_add` / `__phpc_headers_sent`
 * module-locally (`getNamedFunction` first via {@see declarePendingHeaderAbis} /
 * {@see implement}). Do not re-add always-on empty decls in {@see Type} — leftover
 * decls mint pending_header_*.1 (#31894 / #32122 / #33255 / #33891).
 *
 * Embed and thin standalone AOT both NestedJIT via {@see PendingHeadersJitBridge}
 * (IncludePath #20877 shape — no thin stub fork).
 * php-src: ext/standard/head.c
 */
final class PendingHeadersRuntime
{
    public static function ensureLinked(Context $context): void
    {
        // Declare before bridge bodies call lookupFunction (#33891 — was Type::register always-on).
        self::declarePendingHeaderAbis($context);
        self::implement($context);
    }

    /**
     * Module-local empty decls when a call site needs lookup before bodies (#33255 / #33891).
     * Not called from {@see Type::register} — owners call this or {@see ensureLinked}.
     */
    public static function declarePendingHeaderAbis(Context $context): void
    {
        PendingHeadersJitBridge::declarePendingHeaderAbis($context);
    }

    /** Thin AOT: fill pending-header ABI shells for link (#20932). */
    public static function ensureThinAotLinkStubs(Context $context): void
    {
        PendingHeadersJitBridge::fillThinAotLinkStubs($context);
    }

    public static function implement(Context $context): void
    {
        self::declarePendingHeaderAbis($context);
        PendingHeadersJitBridge::implement($context);
    }
}
