--TEST--
AOT: get_declared_functions() — not in php-src (#24223, re-#4780)
--FILE--
<?php
echo function_exists('get_declared_functions') ? "fail\n" : "ok\n";
echo function_exists('get_defined_functions') ? "ok\n" : "fail\n";
--EXPECT--
ok
ok
