--TEST--
JIT: file() — zero-byte file returns empty array (#11710, ext/standard/file.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_file_empty_jit_' . getmypid() . '.txt';
file_put_contents($path, '');
$lines = file($path);
echo 'count=', count($lines), "\n";
$ignore = file($path, FILE_IGNORE_NEW_LINES);
echo 'ignore=', count($ignore), "\n";
unlink($path);
--EXPECT--
count=0
ignore=0
