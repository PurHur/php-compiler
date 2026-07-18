<?php
/**
 * Repro for #20703 — pg_field_name/num/type/size/prtlen registration.
 */
declare(strict_types=1);

foreach (['pg_field_name', 'pg_field_num', 'pg_field_type', 'pg_field_size', 'pg_field_prtlen'] as $f) {
    echo $f, ' => ', function_exists($f) ? 'yes' : 'MISSING', PHP_EOL;
}
