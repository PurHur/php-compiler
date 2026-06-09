--TEST--
stdlib mb_convert_case() multibyte case conversion (VM, #7014)
--FILE--
<?php
echo (int) function_exists('mb_convert_case'), "\n";
echo mb_convert_case('hello', MB_CASE_UPPER, 'UTF-8'), "\n";
echo mb_convert_case('HELLO', MB_CASE_LOWER), "\n";
echo mb_convert_case('hello world', MB_CASE_TITLE, 'UTF-8'), "\n";
--EXPECT--
1
HELLO
hello
Hello World
