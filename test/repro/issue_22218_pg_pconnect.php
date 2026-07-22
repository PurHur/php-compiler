<?php
/**
 * Repro #22218 — pg_pconnect after pg_connect (ext/pgsql).
 */
if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}
echo 'exists=', function_exists('pg_pconnect') ? 'yes' : 'no', "\n";
echo 'sibling=', function_exists('pg_connect') ? 'yes' : 'no', "\n";
try {
    pg_pconnect();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
// Failed connect still advertises the API; false + warning (no live server required).
$conn = @pg_pconnect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope connect_timeout=1');
echo 'fail=', (false === $conn) ? 'yes' : 'no', "\n";
