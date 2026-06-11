--TEST--
stdlib getopt() registered (issue #3251)
--FILE--
<?php
var_export(function_exists('getopt'));
echo "\n";
--EXPECT--
true
