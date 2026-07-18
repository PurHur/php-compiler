--TEST--
ext/pgsql connection info / health APIs (#20680)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
$fns = [
    'pg_version', 'pg_parameter_status', 'pg_host', 'pg_port', 'pg_dbname', 'pg_options', 'pg_tty',
    'pg_client_encoding', 'pg_set_client_encoding', 'pg_ping', 'pg_connection_reset',
    'pg_connection_busy', 'pg_connection_status', 'pg_transaction_status',
];
foreach ($fns as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
foreach ([
    'PGSQL_CONNECTION_OK', 'PGSQL_CONNECTION_BAD', 'PGSQL_TRANSACTION_IDLE', 'PGSQL_TRANSACTION_UNKNOWN',
] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'OK=', (int) constant('PGSQL_CONNECTION_OK'), "\n";
echo 'BAD=', (int) constant('PGSQL_CONNECTION_BAD'), "\n";
echo 'IDLE=', (int) constant('PGSQL_TRANSACTION_IDLE'), "\n";
echo 'UNKNOWN=', (int) constant('PGSQL_TRANSACTION_UNKNOWN'), "\n";
?>
--EXPECT--
pg_version=1
pg_parameter_status=1
pg_host=1
pg_port=1
pg_dbname=1
pg_options=1
pg_tty=1
pg_client_encoding=1
pg_set_client_encoding=1
pg_ping=1
pg_connection_reset=1
pg_connection_busy=1
pg_connection_status=1
pg_transaction_status=1
PGSQL_CONNECTION_OK=1
PGSQL_CONNECTION_BAD=1
PGSQL_TRANSACTION_IDLE=1
PGSQL_TRANSACTION_UNKNOWN=1
OK=0
BAD=1
IDLE=0
UNKNOWN=4
