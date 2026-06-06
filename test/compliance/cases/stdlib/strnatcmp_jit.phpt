--TEST--
JIT: strnatcmp() / strnatcasecmp() (#5517)
--FILE--
<?php
var_export(strnatcmp('2', '10'));
echo "\n";
var_export(strnatcmp('10', '2'));
echo "\n";
var_export(strnatcasecmp('aB', 'Ab'));
echo "\n";
--EXPECT--
-1
1
0
