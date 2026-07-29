<?php
/**
 * Repro #24862 — PROFILE=8.4 date_parse(null) must soft-null (DEP+parse-error array), not TypeError.
 * Zend 8.4 still deprecates; TypeError is PHP 9.0 (RFC deprecate_null_to_scalar_internal_arg).
 * Peer: idate(null) soft-null (#21491); substr(null) (#24817).
 */
error_reporting(E_ALL);
$GLOBALS['date_parse_24862_deps'] = 0;
function date_parse_24862_repro_handler(int $no, string $msg): bool
{
    if (E_DEPRECATED === $no || str_contains($msg, 'Passing null')) {
        $GLOBALS['date_parse_24862_deps']++;
    }

    return true;
}
set_error_handler('date_parse_24862_repro_handler');
try {
    $r = date_parse(null);
    echo 'error_count=', $r['error_count'], "\n";
    echo 'deps=', $GLOBALS['date_parse_24862_deps'], "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
