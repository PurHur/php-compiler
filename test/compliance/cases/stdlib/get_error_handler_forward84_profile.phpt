--TEST--
stdlib get_error_handler()/get_exception_handler() — forward 8.4 profile (#17644, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('get_error_handler') ? '1' : '0';
echo "\n";
echo function_exists('get_exception_handler') ? '1' : '0';
echo "\n";
set_error_handler(static fn () => true);
$h = get_error_handler();
echo is_callable($h) ? "1\n" : "0\n";
restore_error_handler();
echo null === get_error_handler() ? "1\n" : "0\n";
set_exception_handler(static fn () => true);
$ex = get_exception_handler();
echo is_callable($ex) ? "1\n" : "0\n";
restore_exception_handler();
echo null === get_exception_handler() ? "1\n" : "0\n";
--EXPECT--
1
1
1
1
1
1
