<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Request-scoped mod_rewrite var table for compiled JIT/AOT modules (#9753, php-in-PHP).
 *
 * Append-only string blob so add/reset JIT-compile for AOT standalone; duplicate names
 * resolve last-wins when decoded (php-src output_add_rewrite_var replace semantics).
 * Also holds url_rewriter.tags/hosts for AOT so Ini + Flush share one NestedJIT TU (#27566).
 * VM introspection via {@see VmOutputRewriteVars::list()}.
 * OB registration is NOT done here — VM uses {@see VmUrlRewriterOb::ensureRegistered}
 * via {@see \PHPCompiler\Web\ResponseContext::addRewriteVar}; AOT/JIT uses
 * `__phpc_ob_start_with_url_rewriter` (#27566).
 * php-src: ext/standard/url.c — output_add_rewrite_var, output_reset_rewrite_vars
 */
final class OutputRewriteVarsJitHelper
{
    private static string $blob = '';

    private static string $tags = 'form=';

    private static string $hosts = '';

    public static function exportBlob(): string
    {
        return self::$blob;
    }

    public static function getTags(): string
    {
        return self::$tags;
    }

    public static function setTags(string $tags): void
    {
        self::$tags = $tags;
    }

    public static function getHosts(): string
    {
        return self::$hosts;
    }

    public static function setHosts(string $hosts): void
    {
        self::$hosts = $hosts;
    }

    public static function add(string $name, string $value): void
    {
        $record = $name."\x1E".$value;
        if ('' === self::$blob) {
            self::$blob = $record;
        } else {
            self::$blob = self::$blob."\x1D".$record;
        }
    }

    public static function reset(): void
    {
        self::$blob = '';
        VmUrlRewriterOb::resetState();
    }
}
