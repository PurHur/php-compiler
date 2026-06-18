<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Request-scoped mod_rewrite var table for compiled JIT/AOT modules (#9753, php-in-PHP).
 *
 * Append-only string blob so add/reset JIT-compile for AOT standalone; duplicate names
 * resolve last-wins when decoded (php-src output_add_rewrite_var replace semantics).
 * VM introspection via {@see VmOutputRewriteVars::list()}.
 * php-src: ext/standard/url.c — output_add_rewrite_var, output_reset_rewrite_vars
 */
final class OutputRewriteVarsJitHelper
{
    private static string $blob = '';

    private const RECORD_SEP = "\x1D";

    private const FIELD_SEP = "\x1E";

    public static function exportBlob(): string
    {
        return self::$blob;
    }

    public static function add(string $name, string $value): bool
    {
        $record = $name.self::FIELD_SEP.$value;
        if ('' === self::$blob) {
            self::$blob = $record;
        } else {
            self::$blob = self::$blob.self::RECORD_SEP.$record;
        }

        return true;
    }

    public static function reset(): bool
    {
        self::$blob = '';

        return true;
    }
}
