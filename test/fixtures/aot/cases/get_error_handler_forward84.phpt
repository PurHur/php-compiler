--TEST--
AOT: get_error_handler()/get_exception_handler() compile + round-trip (#17668, #17671, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

function err_handler($e, $s, $f, $l)
{
    return true;
}

echo (int) function_exists('get_error_handler'), "\n";
echo (int) function_exists('get_exception_handler'), "\n";
echo (int) (null === get_error_handler()), "\n";
echo (int) (null === get_exception_handler()), "\n";
set_error_handler('err_handler');
echo get_error_handler() ?? 'null', "\n";
--EXPECT--
1
1
1
1
err_handler
