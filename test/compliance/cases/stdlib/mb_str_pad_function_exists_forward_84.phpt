--TEST--
stdlib mb_str_pad() — advertised on PHP 8.4 forward profile (#16776, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('mb_str_pad')) {
    echo "fail: function_exists\n";
    exit(1);
}
if (!is_callable('mb_str_pad')) {
    echo "fail: is_callable\n";
    exit(1);
}
echo mb_str_pad('hi', 5, '-'), "\n";
--EXPECT--
hi---
