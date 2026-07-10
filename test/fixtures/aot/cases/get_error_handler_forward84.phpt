--TEST--
AOT: get_error_handler()/get_exception_handler() compile + empty-stack introspection (#17668, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo (int) function_exists('get_error_handler'), "\n";
echo (int) function_exists('get_exception_handler'), "\n";
echo (int) (null === get_error_handler()), "\n";
echo (int) (null === get_exception_handler()), "\n";
--EXPECT--
1
1
1
1
