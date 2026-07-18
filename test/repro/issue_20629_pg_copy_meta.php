<?php
/**
 * Repro for #20629 — pg_copy_to/from, meta_data, convert, field_* registration when libpq advertises.
 */
declare(strict_types=1);

if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}

foreach ([
    'pg_copy_to',
    'pg_copy_from',
    'pg_meta_data',
    'pg_convert',
    'pg_field_table',
    'pg_field_type_oid',
    'pg_field_is_null',
] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
