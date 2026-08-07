--TEST--
stdlib get_object_id() — not advertised on PHP 8.2 reference profile (#17564, #28405)
--FILE--
<?php
echo function_exists('get_object_id') ? "fail\n" : "ok\n";
echo function_exists('spl_object_id') ? "spl-ok\n" : "spl-fail\n";
--EXPECT--
ok
spl-ok
