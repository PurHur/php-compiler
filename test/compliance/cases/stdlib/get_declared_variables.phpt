--TEST--
stdlib get_declared_variables() — not in php-src (#24223, re-#4780, ext/standard/basic_functions.stub.php)
--FILE--
<?php
echo function_exists('get_declared_variables') ? "fail\n" : "ok\n";
echo function_exists('get_defined_vars') ? "ok\n" : "fail\n";
--EXPECT--
ok
ok
