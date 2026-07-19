--TEST--
stdlib get_error_handler()/get_exception_handler() — not advertised under PROFILE=8.4 (#21175)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('get_error_handler') ? "fail_error\n" : "ok_error\n";
echo function_exists('get_exception_handler') ? "fail_exception\n" : "ok_exception\n";
--EXPECT--
ok_error
ok_exception
