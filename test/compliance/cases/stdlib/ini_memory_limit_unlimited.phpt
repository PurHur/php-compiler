--TEST--
stdlib ini_set('memory_limit') accepts unlimited -1 (issue #5138, Zend INI)
--FILE--
<?php
$unlimited = '-'.'1';
$old = ini_set('memory_limit', $unlimited);
echo is_string($old) ? "set-ok\n" : "set-fail\n";
echo ini_get('memory_limit') === $unlimited ? "get-ok\n" : "get-fail\n";
--EXPECT--
set-ok
get-ok
