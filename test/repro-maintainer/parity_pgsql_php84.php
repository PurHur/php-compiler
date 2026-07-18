<?php
declare(strict_types=1);

foreach ([
    'pg_change_password',
    'pg_jit',
    'pg_put_copy_data',
    'pg_put_copy_end',
    'pg_result_memory_size',
    'pg_set_chunked_rows_size',
    'pg_socket_poll',
] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
