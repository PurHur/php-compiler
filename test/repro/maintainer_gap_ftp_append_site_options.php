<?php
$funcs = [
    'ftp_append',
    'ftp_alloc',
    'ftp_chmod',
    'ftp_raw',
    'ftp_site',
    'ftp_set_option',
    'ftp_get_option',
];
foreach ($funcs as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}
