--TEST--
Stdlib: get_error_handler()/get_exception_handler() round-trip (#17644, #21175, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

function err_handler(int $errno, string $errstr): bool
{
    return true;
}

function ex_handler(Throwable $e): void
{
}

echo (int) function_exists('get_error_handler'), "\n";
echo (int) function_exists('get_exception_handler'), "\n";
echo (int) (null === get_error_handler()), "\n";
set_error_handler('err_handler');
echo (int) ('err_handler' === get_error_handler()), "\n";
restore_error_handler();
echo (int) (null === get_error_handler()), "\n";
echo (int) (null === get_exception_handler()), "\n";
set_exception_handler('ex_handler');
echo (int) ('ex_handler' === get_exception_handler()), "\n";
restore_exception_handler();
echo (int) (null === get_exception_handler()), "\n";
--EXPECT--
1
1
1
1
1
1
1
1
