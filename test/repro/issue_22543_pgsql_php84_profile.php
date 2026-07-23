<?php
// Repro for #22543 — PHP 8.4 pgsql helpers must follow language profile, not VERSION_ID
declare(strict_types=1);

foreach ([
    'pg_result_memory_size',
    'pg_change_password',
    'pg_jit',
    'pg_put_copy_data',
    'pg_put_copy_end',
    'pg_socket_poll',
] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
