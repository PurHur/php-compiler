<?php
declare(strict_types=1);

// #23190 — stream_get_contents() offset < -1 must not seek / not return false (php-src file.c).
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcdef');
var_export(stream_get_contents($f, 2, -2));
echo "\n";
var_export(stream_get_contents($f, 2, -7));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, -1, -5));
echo "\n";

$path = sys_get_temp_dir() . '/phpc_issue_23190.txt';
file_put_contents($path, 'abcdef');
$f = fopen($path, 'r');
fseek($f, 6);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
fclose($f);
@unlink($path);
