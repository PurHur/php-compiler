--TEST--
stdlib readfile() php://output returns -1 stdout sentinel (issue #18417)
--FILE--
<?php
var_export(readfile('php://output'));
echo "\n";
var_export(readfile('php://stdin'));
echo "\n";
var_export(readfile('php://memory'));
echo "\n";
--EXPECT--
-1
0
0
