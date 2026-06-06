--TEST--
AOT: strnatcmp() / strnatcasecmp() (#5517)
--FILE--
<?php
var_export(strnatcmp('2', '10'));
echo "\n";
var_export(strnatcasecmp('aB', 'Ab'));
echo "\n";
--EXPECT--
-1
0
