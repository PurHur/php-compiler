<?php
declare(strict_types=1);
$host = getenv('PHP_COMPILER_PGSQL_HOST') ?: '127.0.0.1';
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
$conninfo = "host={$host} port={$port} dbname=test user=test password=test";
echo 'ext=', (int) extension_loaded('pgsql'), PHP_EOL;
echo 'fn=', (int) function_exists('pg_connect'), PHP_EOL;
$bad = @pg_connect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope connect_timeout=1');
echo 'bad=', var_export($bad, true), PHP_EOL;
echo 'bad_err=', pg_last_error(), PHP_EOL;
$conn = pg_connect($conninfo);
if (false === $conn) {
    echo 'connect_fail=', pg_last_error(), PHP_EOL;
    exit(1);
}
echo 'connected=1', PHP_EOL;
$res = pg_query($conn, 'SELECT 1 AS n');
if (false === $res) {
    echo 'query_fail=', pg_last_error($conn), PHP_EOL;
    exit(1);
}
echo 'num=', pg_num_rows($res), PHP_EOL;
var_export(pg_fetch_assoc($res));
echo PHP_EOL;
pg_close($conn);
echo "ok\n";
