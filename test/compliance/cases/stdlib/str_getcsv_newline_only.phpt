--TEST--
stdlib str_getcsv() newline-only input yields NULL field (#10623)
--FILE--
<?php
$lf = str_getcsv("\n");
$crlf = str_getcsv("\r\n");
$cr = str_getcsv("\r");
var_export($lf === [null]);
echo "\n";
var_export($crlf === [null]);
echo "\n";
var_export($cr === [null]);
echo "\n";
--EXPECT--
true
true
true
