--TEST--
AOT extract() null $flags coerce to EXTR_OVERWRITE (#31194, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$arr = ['a' => 1];
$n = extract($arr, null);
var_export($n);
echo "\n";
echo $a, "\n";
--EXPECT--
1
1
