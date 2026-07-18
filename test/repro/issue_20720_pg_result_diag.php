<?php

/**
 * Repro for #20720 — pg_result_error / pg_result_error_field / pg_last_oid.
 */
declare(strict_types=1);

foreach (['pg_result_error', 'pg_result_error_field', 'pg_last_oid', 'pg_fetch_assoc', 'pg_query'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
foreach (['PGSQL_DIAG_SEVERITY', 'PGSQL_DIAG_SQLSTATE', 'PGSQL_DIAG_MESSAGE_PRIMARY'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
echo 'SEVERITY=', (int) constant('PGSQL_DIAG_SEVERITY'), "\n";
echo 'SQLSTATE=', (int) constant('PGSQL_DIAG_SQLSTATE'), "\n";
