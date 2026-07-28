--TEST--
stdlib get_declared_attributes() — not in php-src (#24222, re-#6450, ext/reflection/php_reflection.c)
--FILE--
<?php
echo function_exists('get_declared_attributes') ? "fail\n" : "ok\n";
--EXPECT--
ok
