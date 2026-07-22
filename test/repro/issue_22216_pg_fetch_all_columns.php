<?php
/**
 * Repro #22216 — pg_fetch_all_columns after pg_fetch_all (ext/pgsql).
 */
if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}
echo 'exists=', function_exists('pg_fetch_all_columns') ? 'yes' : 'no', "\n";
echo 'sibling=', function_exists('pg_fetch_all') ? 'yes' : 'no', "\n";
try {
    pg_fetch_all_columns();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
