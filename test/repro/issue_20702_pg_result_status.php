<?php
/**
 * Repro for #20702 — pg_result_status / pg_get_pid + PGSQL_STATUS_* registration.
 */
declare(strict_types=1);

foreach (['pg_result_status', 'pg_get_pid'] as $f) {
    echo $f, ' => ', function_exists($f) ? 'yes' : 'MISSING', PHP_EOL;
}
foreach (['PGSQL_STATUS_LONG', 'PGSQL_STATUS_STRING'] as $c) {
    echo $c, ' => ', defined($c) ? 'yes' : 'MISSING', PHP_EOL;
}
if (defined('PGSQL_STATUS_LONG')) {
    echo 'LONG=', PGSQL_STATUS_LONG, PHP_EOL;
}
if (defined('PGSQL_STATUS_STRING')) {
    echo 'STRING=', PGSQL_STATUS_STRING, PHP_EOL;
}
