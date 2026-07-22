<?php

declare(strict_types=1);

/** Repro #22195 — mysqli result fetch / field metadata registration. */
$funcs = [
    'mysqli_query',
    'mysqli_fetch_assoc',
    'mysqli_fetch_row',
    'mysqli_fetch_array',
    'mysqli_fetch_all',
    'mysqli_fetch_object',
    'mysqli_fetch_field',
    'mysqli_fetch_fields',
    'mysqli_fetch_field_direct',
    'mysqli_fetch_lengths',
    'mysqli_data_seek',
    'mysqli_field_seek',
    'mysqli_field_tell',
    'mysqli_num_fields',
];
foreach ($funcs as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
