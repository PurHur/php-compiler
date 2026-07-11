--TEST--
stdlib tempnam() — empty directory silent fallback (#11701, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL);
$path = tempnam('', 'phpc_');
echo is_string($path) ? "ok\n" : "fail\n";
--EXPECT--
ok
