--TEST--
stdlib file() — zero-byte file returns empty array (#11710, ext/standard/file.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_file_empty_' . getmypid() . '.txt';
file_put_contents($path, '');
$lines = file($path);
echo 'count=', count($lines), "\n";
$ignore = file($path, FILE_IGNORE_NEW_LINES);
echo 'ignore=', count($ignore), "\n";
$skip = file_put_contents($path, "\n\n");
$skipLines = file($path, FILE_SKIP_EMPTY_LINES);
echo 'skip=', count($skipLines), "\n";
unlink($path);
--EXPECT--
count=0
ignore=0
skip=0
