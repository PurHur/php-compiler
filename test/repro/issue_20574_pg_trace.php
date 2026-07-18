<?php
// Repro for #20574 — pg_trace / pg_untrace advertisement (+ optional live round-trip)
foreach (['pg_trace', 'pg_untrace', 'pg_connect', 'pg_query'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}
if (!function_exists('pg_trace')) {
    exit(0);
}
echo 'no_default=', (int) @pg_trace('/tmp/phpc_pg_trace_none.log'), "\n";
$host = getenv('PHP_COMPILER_PGSQL_HOST');
if (false === $host || '' === $host) {
    exit(0);
}
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
$path = sys_get_temp_dir().'/phpc_pg_trace_'.getmypid().'.log';
@unlink($path);
$conn = @pg_connect("host={$host} port={$port} dbname=test user=test password=test connect_timeout=2");
if (false === $conn) {
    echo "live=unreachable\n";
    exit(0);
}
echo 'live_trace=', (int) pg_trace($path, 'w', $conn), "\n";
@pg_query($conn, 'SELECT 1');
echo 'live_untrace=', (int) pg_untrace($conn), "\n";
echo 'live_size=', (int) (@filesize($path) > 0), "\n";
pg_close($conn);
@unlink($path);
