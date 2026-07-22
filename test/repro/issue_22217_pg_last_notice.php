<?php
/**
 * Repro #22217 — pg_last_notice + PGSQL_NOTICE_* after pg_last_error (ext/pgsql).
 */
if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}
echo 'exists=', function_exists('pg_last_notice') ? 'yes' : 'no', "\n";
echo 'sibling=', function_exists('pg_last_error') ? 'yes' : 'no', "\n";
foreach (['PGSQL_NOTICE_LAST', 'PGSQL_NOTICE_ALL', 'PGSQL_NOTICE_CLEAR'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'missing', "\n";
}
try {
    pg_last_notice();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
