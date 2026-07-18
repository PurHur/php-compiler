<?php

/**
 * Repro for #20704 — pg_fetch_array/object/result + pg_free_result/pg_result_seek.
 */
declare(strict_types=1);

foreach (['pg_fetch_array', 'pg_fetch_object', 'pg_fetch_result', 'pg_free_result', 'pg_result_seek'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
