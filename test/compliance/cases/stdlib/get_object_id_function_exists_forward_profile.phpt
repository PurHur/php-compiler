--TEST--
stdlib get_object_id() — function_exists on PHP_COMPILER_PROFILE=8.3 (#17564, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo function_exists('get_object_id') ? '1' : '0';
echo "\n";
echo is_callable('get_object_id') ? '1' : '0';
echo "\n";
class A {}
$o = new A();
echo get_object_id($o) > 0 ? "1\n" : "0\n";
--EXPECT--
1
1
1
