<?php
/**
 * Repro for #20637 — pg_insert/update/delete/select registration when libpq advertises.
 */
declare(strict_types=1);

if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}

foreach (['pg_insert', 'pg_update', 'pg_delete', 'pg_select'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
echo 'PGSQL_DML_EXEC=', defined('PGSQL_DML_EXEC') ? (string) PGSQL_DML_EXEC : 'undef', PHP_EOL;
