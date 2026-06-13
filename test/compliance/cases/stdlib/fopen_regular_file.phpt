--TEST--
stdlib fopen()/readfile() on regular filesystem paths (#5214, ext/standard/streams.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-fopen-reg-' . getmypid() . '.txt';
file_put_contents($path, 'xy');
var_export(fopen($path, 'r') !== false);
echo "\n";
var_export(file_get_contents($path));
echo "\n";
@unlink($path);

$rf = sys_get_temp_dir() . '/phpc-readfile-reg-' . getmypid() . '.txt';
file_put_contents($rf, 'data');
$n = readfile($rf);
echo "\n", $n, "\n";
@unlink($rf);
--EXPECT--
true
'xy'
data
4
