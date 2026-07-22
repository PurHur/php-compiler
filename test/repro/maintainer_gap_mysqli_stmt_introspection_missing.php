<?php

declare(strict_types=1);

/** Repro #22193 — mysqli_stmt introspection registration. */
$funcs = [
    'mysqli_prepare',
    'mysqli_stmt_execute',
    'mysqli_stmt_field_count',
    'mysqli_stmt_param_count',
    'mysqli_stmt_sqlstate',
    'mysqli_stmt_errno',
    'mysqli_stmt_error',
    'mysqli_stmt_insert_id',
    'mysqli_stmt_num_rows',
    'mysqli_stmt_affected_rows',
    'mysqli_stmt_data_seek',
    'mysqli_stmt_reset',
    'mysqli_stmt_store_result',
    'mysqli_stmt_free_result',
    'mysqli_stmt_result_metadata',
];
foreach ($funcs as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
