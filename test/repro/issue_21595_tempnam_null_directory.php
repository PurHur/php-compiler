<?php
/**
 * Repro #21595 — tempnam(null) soft-null under PROFILE=8.4 (php-src ext/standard/file.c).
 *
 * Expect: E_DEPRECATED + system-temp path (not TypeError).
 * Named handler — AOT rejects closure set_error_handler (#1379).
 */
error_reporting(E_ALL);

function issue_21595_dep_handler(int $no, string $msg): bool
{
    if (E_DEPRECATED === $no) {
        echo 'DEP: ', $msg, "\n";
        return true;
    }
    return false;
}

set_error_handler('issue_21595_dep_handler');

try {
    $p = tempnam(null, 'x');
    echo 'dir_null: ', is_string($p) ? 'path' : var_export($p, true), "\n";
    if (is_string($p)) {
        @unlink($p);
    }
} catch (Throwable $e) {
    echo 'dir_null: ', get_class($e), "\n";
}
