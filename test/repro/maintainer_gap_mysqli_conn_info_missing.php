<?php

declare(strict_types=1);

/** Repro #22194 — mysqli connection metadata registration. */
$funcs = [
    'mysqli_connect',
    'mysqli_insert_id',
    'mysqli_field_count',
    'mysqli_sqlstate',
    'mysqli_warning_count',
    'mysqli_character_set_name',
    'mysqli_get_charset',
    'mysqli_get_server_info',
    'mysqli_get_host_info',
    'mysqli_get_proto_info',
    'mysqli_get_client_info',
    'mysqli_get_client_version',
    'mysqli_get_server_version',
    'mysqli_ssl_set',
];
foreach ($funcs as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
