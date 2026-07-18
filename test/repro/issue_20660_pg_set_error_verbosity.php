<?php
/**
 * Repro #20660 — pg_set_error_verbosity + PGSQL_ERRORS_* after #20637.
 */
echo 'fn=', function_exists('pg_set_error_verbosity') ? '1' : '0', "\n";
foreach (['PGSQL_ERRORS_TERSE', 'PGSQL_ERRORS_DEFAULT', 'PGSQL_ERRORS_VERBOSE', 'PGSQL_ERRORS_SQLSTATE'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
if (defined('PGSQL_ERRORS_TERSE')) {
    echo 'TERSE=', (string) constant('PGSQL_ERRORS_TERSE'), "\n";
    echo 'DEFAULT=', (string) constant('PGSQL_ERRORS_DEFAULT'), "\n";
    echo 'VERBOSE=', (string) constant('PGSQL_ERRORS_VERBOSE'), "\n";
    echo 'SQLSTATE=', (string) constant('PGSQL_ERRORS_SQLSTATE'), "\n";
}
try {
    pg_set_error_verbosity();
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
