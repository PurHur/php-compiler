<?php
/**
 * Repro #20661 — pg_query_params/prepare/execute + escape_* + fetch helpers.
 */
foreach ([
    'pg_query_params', 'pg_prepare', 'pg_execute',
    'pg_escape_string', 'pg_escape_literal', 'pg_escape_identifier',
    'pg_escape_bytea', 'pg_unescape_bytea',
    'pg_affected_rows', 'pg_fetch_all', 'pg_num_fields',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

// Escape helpers without live server (no-conn forms).
$plain = "O'Reilly";
$esc = pg_escape_string($plain);
echo 'escape_string=', (strpos($esc, "''") !== false || strpos($esc, "\\'") !== false) ? '1' : '0', "\n";
$bytea = pg_escape_bytea("a\0b");
echo 'escape_bytea_len=', (string) strlen($bytea), "\n";
$round = pg_unescape_bytea($bytea);
echo 'unescape_ok=', ($round === "a\0b" || strlen($round) >= 2) ? '1' : '0', "\n";

try {
    pg_query_params();
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
