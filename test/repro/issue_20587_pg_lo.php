<?php
// Repro for #20587 — pg_lo_* + PgSql\Lob advertisement
$fns = [
    'pg_lo_create', 'pg_lo_open', 'pg_lo_close', 'pg_lo_read', 'pg_lo_write',
    'pg_lo_seek', 'pg_lo_tell', 'pg_lo_truncate', 'pg_lo_unlink',
    'pg_lo_import', 'pg_lo_export', 'pg_lo_read_all',
];
foreach ($fns as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}
echo 'Lob: ', class_exists('PgSql\\Lob') ? 'yes' : 'no', "\n";
$host = getenv('PHP_COMPILER_PGSQL_HOST');
if (false === $host || '' === $host || !function_exists('pg_lo_create')) {
    exit(0);
}
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
$conn = @pg_connect("host={$host} port={$port} dbname=test user=test password=test connect_timeout=2");
if (false === $conn) {
    echo "live=unreachable\n";
    exit(0);
}
pg_query($conn, 'BEGIN');
$oid = pg_lo_create($conn);
$lob = pg_lo_open($conn, $oid, 'w');
pg_lo_write($lob, 'hello-lo');
pg_lo_seek($lob, 0, 0);
$back = pg_lo_read($lob, 32);
pg_lo_close($lob);
pg_lo_unlink($conn, $oid);
pg_query($conn, 'COMMIT');
pg_close($conn);
echo 'roundtrip=', $back, "\n";
