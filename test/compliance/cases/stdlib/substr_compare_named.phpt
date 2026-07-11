--TEST--
stdlib substr_compare() named offset/length parameters (#10320)
--FILE--
<?php
var_export(substr_compare('abc', 'ab', offset: 0, length: 2));
echo "\n";
?>
--EXPECT--
0
