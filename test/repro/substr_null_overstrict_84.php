<?php
/**
 * Repro #24817 — PROFILE=8.4 substr(null) must soft-null (DEP+coerce), not TypeError.
 * Zend 8.4 still deprecates; TypeError is PHP 9.0 (RFC deprecate_null_to_scalar_internal_arg).
 * Peer: mb_substr(null) already matches (#24585).
 * Named handler so the same file AOT-compiles (closures deferred for set_error_handler).
 */
error_reporting(E_ALL);
$GLOBALS['substr_24817_deps'] = 0;
function substr_24817_repro_handler(int $no, string $msg): bool
{
    if (E_DEPRECATED === $no || str_contains($msg, 'Passing null')) {
        $GLOBALS['substr_24817_deps']++;
    }

    return true;
}
set_error_handler('substr_24817_repro_handler');
try {
    $r = substr(null, 0);
    echo 'result=', var_export($r, true), "\n";
    echo 'deps=', $GLOBALS['substr_24817_deps'], "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
