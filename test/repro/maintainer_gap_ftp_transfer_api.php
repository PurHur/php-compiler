<?php
$funcs = [
    'ftp_connect',
    'ftp_pasv',
    'ftp_get',
    'ftp_put',
    'ftp_nlist',
    'ftp_chdir',
    'ftp_mkdir',
    'ftp_delete',
    'ftp_size',
    'ftp_mdtm',
    'ftp_rawlist',
];
foreach ($funcs as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}
