--TEST--
AOT: mb_str_pad() — withheld on 8.2 reference (#16776, #31174)
--FILE--
<?php
echo function_exists('mb_str_pad') ? "exists_fail\n" : "exists_ok\n";
echo is_callable('mb_str_pad') ? "callable_fail\n" : "callable_ok\n";
--EXPECT--
exists_ok
callable_ok
