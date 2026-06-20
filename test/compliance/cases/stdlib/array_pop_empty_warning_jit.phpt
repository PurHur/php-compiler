--TEST--
stdlib array_pop()/array_shift() JIT — empty arrays silent like Zend PHP 8.2+ (#4791, #10194, #10322)
--FILE--
<?php
$a = [];
var_export(array_pop($a));
echo "\n";
$b = [];
var_export(array_shift($b));
echo "\n";
--EXPECT--
NULL
NULL
