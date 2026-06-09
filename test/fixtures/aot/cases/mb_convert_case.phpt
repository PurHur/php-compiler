--TEST--
AOT: mb_convert_case() multibyte case conversion (#7014)
--FILE--
<?php
echo (int) function_exists('mb_convert_case'), "\n";
echo mb_convert_case('hello', MB_CASE_UPPER, 'UTF-8'), "\n";
echo mb_convert_case('HELLO', MB_CASE_LOWER), "\n";
--EXPECT--
1
HELLO
hello
