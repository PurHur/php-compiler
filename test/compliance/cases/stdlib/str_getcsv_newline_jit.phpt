--TEST--
JIT: str_getcsv() newline-only input (#10623)
--FILE--
<?php
$row = str_getcsv("\n");
echo ($row === [null]) ? 'ok' : 'fail';
echo "\n";
--EXPECT--
ok
