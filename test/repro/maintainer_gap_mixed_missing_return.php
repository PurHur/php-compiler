<?php

/**
 * Repro for #26485 — `: mixed` with no returned value must TypeError (Zend/zend_execute.c).
 * Untyped and `: void` must still return null without error; `return null` on `: mixed` is OK.
 *
 * Note: bare `return;` under `: mixed` is a Zend compile-time fatal; VM/JIT enforce at runtime
 * (see compliance case language/mixed_missing_return).
 */

function missing_mixed(): mixed
{
}

function ok_mixed_null(): mixed
{
    return null;
}

function untyped_ok()
{
}

function void_ok(): void
{
}

foreach ([
    'missing_mixed',
    'ok_mixed_null',
    'untyped_ok',
    'void_ok',
] as $name) {
    try {
        $r = $name();
        echo $name, '=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
