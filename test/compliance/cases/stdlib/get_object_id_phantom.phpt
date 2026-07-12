--TEST--
stdlib get_object_id() — not advertised on PHP 8.2 reference profile (#17564, ext/standard/basic_functions.c)
--FILE--
<?php
echo function_exists('get_object_id') ? "fail\n" : "ok\n";
--EXPECT--
ok
