<?php
/**
 * Repro for #29858 — AOT `:int` return of non-numeric string must TypeError (zend_verify_return_type).
 */
function f(): int
{
    return 'x';
}

try {
    var_export(f());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
