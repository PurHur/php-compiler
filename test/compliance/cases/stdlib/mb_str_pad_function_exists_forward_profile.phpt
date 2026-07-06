--TEST--
stdlib mb_str_pad() — function_exists on PHP_COMPILER_PROFILE=8.4 (#16776, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('mb_str_pad') ? '1' : '0';
echo "\n";
echo is_callable('mb_str_pad') ? '1' : '0';
echo "\n";
echo mb_str_pad('hi', 5, '-'), "\n";
--EXPECT--
1
1
hi---
