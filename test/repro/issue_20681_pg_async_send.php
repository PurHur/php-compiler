<?php
/**
 * Repro for #20681 — pg_send_* / pg_get_result / pg_cancel_query / pg_get_notify registration.
 * Live async round-trip SKIPIF when no Postgres DSN.
 */
declare(strict_types=1);

$fns = [
    'pg_send_query',
    'pg_send_query_params',
    'pg_send_prepare',
    'pg_send_execute',
    'pg_get_result',
    'pg_cancel_query',
    'pg_get_notify',
];
foreach ($fns as $f) {
    echo $f, ' => ', function_exists($f) ? 'yes' : 'MISSING', PHP_EOL;
}

$dsn = getenv('PHP_COMPILER_PGSQL_DSN');
if (false === $dsn || '' === $dsn) {
    echo "live=skip (no PHP_COMPILER_PGSQL_DSN)\n";
    exit(0);
}
if (!function_exists('pg_connect')) {
    echo "live=skip (no pg_connect)\n";
    exit(0);
}

$conn = @pg_connect($dsn);
if (false === $conn) {
    echo "live=skip (connect failed)\n";
    exit(0);
}

$sent = pg_send_query($conn, 'SELECT 1 AS n');
echo 'send=', var_export($sent, true), PHP_EOL;
while (pg_connection_busy($conn)) {
    pg_consume_input($conn);
}
$res = pg_get_result($conn);
echo 'result=', (false === $res ? 'false' : get_class($res)), PHP_EOL;
if (false !== $res) {
    $row = pg_fetch_assoc($res);
    echo 'n=', isset($row['n']) ? $row['n'] : 'missing', PHP_EOL;
}
// Drain trailing NULL result
while (false !== pg_get_result($conn)) {
}
$cancel = pg_cancel_query($conn);
echo 'cancel=', var_export($cancel, true), PHP_EOL;
$notify = pg_get_notify($conn);
echo 'notify=', var_export($notify, true), PHP_EOL;
pg_close($conn);
echo "live=ok\n";
