<?php
/**
 * Repro #20674 — pg_set_error_context_visibility + PGSQL_SHOW_CONTEXT_* after #20637.
 * PHP 8.3+ only (#22620): run with PHP_COMPILER_PROFILE=8.3 or 8.4.
 */
echo 'fn=', function_exists('pg_set_error_context_visibility') ? '1' : '0', "\n";
foreach (['PGSQL_SHOW_CONTEXT_NEVER', 'PGSQL_SHOW_CONTEXT_ERRORS', 'PGSQL_SHOW_CONTEXT_ALWAYS'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
if (!function_exists('pg_set_error_context_visibility')) {
    echo "skip: requires PROFILE>=8.3 (#22620)\n";
    exit(0);
}
echo 'NEVER=', (string) constant('PGSQL_SHOW_CONTEXT_NEVER'), "\n";
echo 'ERRORS=', (string) constant('PGSQL_SHOW_CONTEXT_ERRORS'), "\n";
echo 'ALWAYS=', (string) constant('PGSQL_SHOW_CONTEXT_ALWAYS'), "\n";
try {
    pg_set_error_context_visibility();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
