<?php

declare(strict_types=1);

// #9407 — gzopen wrapper parity vs php-src ext/zlib/zlib.c
var_export(fopen('php://memory', 'r+') !== false);
echo "\n";
var_export(gzopen('php://memory', 'w+b') !== false);
echo "\n";
var_export(gzopen('php://memory', 'wb') !== false);
echo "\n";
var_export(gzopen('php://temp', 'rb') !== false);
echo "\n";
$fp = gzopen('php://temp', 'rb');
if (false !== $fp) {
    var_export(gzread($fp, 10));
    echo "\n";
    var_export(gzeof($fp));
    echo "\n";
    gzclose($fp);
}
$tmp = sys_get_temp_dir().'/phpc_gzopen_'.getmypid().'.gz';
var_export(gzopen($tmp, 'w9') !== false);
echo "\n";
@unlink($tmp);
