<?php
/**
 * Repro #20680 — pg connection info / health APIs after #20637.
 */
$fns = [
    'pg_version', 'pg_parameter_status', 'pg_host', 'pg_port', 'pg_dbname', 'pg_options', 'pg_tty',
    'pg_client_encoding', 'pg_set_client_encoding', 'pg_ping', 'pg_connection_reset',
    'pg_connection_busy', 'pg_connection_status', 'pg_transaction_status',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$consts = [
    'PGSQL_CONNECTION_OK', 'PGSQL_CONNECTION_BAD', 'PGSQL_CONNECTION_STARTED', 'PGSQL_CONNECTION_MADE',
    'PGSQL_CONNECTION_AWAITING_RESPONSE', 'PGSQL_CONNECTION_AUTH_OK', 'PGSQL_CONNECTION_SETENV',
    'PGSQL_CONNECTION_SSL_STARTUP', 'PGSQL_TRANSACTION_IDLE', 'PGSQL_TRANSACTION_ACTIVE',
    'PGSQL_TRANSACTION_INTRANS', 'PGSQL_TRANSACTION_INERROR', 'PGSQL_TRANSACTION_UNKNOWN',
];
foreach ($consts as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
if (defined('PGSQL_CONNECTION_OK')) {
    echo 'OK=', (string) constant('PGSQL_CONNECTION_OK'), "\n";
    echo 'BAD=', (string) constant('PGSQL_CONNECTION_BAD'), "\n";
    echo 'IDLE=', (string) constant('PGSQL_TRANSACTION_IDLE'), "\n";
}
