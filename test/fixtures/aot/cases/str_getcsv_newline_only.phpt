--TEST--
AOT: str_getcsv() newline-only input (#10623)
--FILE--
<?php
$row = str_getcsv("\n");
echo ($row === [null]) ? 'ok' : 'fail';
echo "\n";
$row2 = str_getcsv("\r\n");
echo ($row2 === [null]) ? 'ok' : 'fail';
echo "\n";
--EXPECT--
ok
ok
