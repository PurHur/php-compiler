--TEST--
stdlib file_get_contents() offset:/length: named parameters (#11894, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_fgc_named_' . getmypid() . '.txt';
file_put_contents($path, 'hello world');
$pos = file_get_contents($path, false, null, 0, 5);
$named = file_get_contents($path, offset: 0, length: 5);
echo 'positional=', var_export($pos, true), "\n";
echo 'named=', var_export($named, true), "\n";
unlink($path);
--EXPECT--
positional='hello'
named='hello'
