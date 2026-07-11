--TEST--
Regression: get_declared_traits() nested in in_array() returns array (#13507, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

echo in_array('Traversable', get_declared_traits(), true) ? "true\n" : "false\n";
echo in_array('md5', hash_algos(), true) ? "true\n" : "false\n";
--EXPECT--
false
true
