--TEST--
stdlib file_put_contents() — null $data coerces JIT (#17024, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$path = tempnam(sys_get_temp_dir(), 'fpc_null_');
$written = file_put_contents($path, null);
echo 0 === $written && 0 === filesize($path) ? "ok\n" : "fail\n";
@unlink($path);
--EXPECT--
ok
