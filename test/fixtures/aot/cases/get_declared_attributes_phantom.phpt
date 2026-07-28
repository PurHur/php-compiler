--TEST--
AOT: get_declared_attributes() — not in php-src (#24222)
--FILE--
<?php
echo function_exists('get_declared_attributes') ? "fail\n" : "ok\n";
--EXPECT--
ok
