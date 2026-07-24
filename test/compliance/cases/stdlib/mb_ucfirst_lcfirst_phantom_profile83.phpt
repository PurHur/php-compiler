--TEST--
stdlib mb_ucfirst()/mb_lcfirst() — withheld on PHP_COMPILER_PROFILE=8.3 (#22794, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo function_exists('mb_ucfirst') ? "fail_uc\n" : "ok_uc\n";
echo function_exists('mb_lcfirst') ? "fail_lc\n" : "ok_lc\n";
echo function_exists('mb_str_pad') ? "ok_pad\n" : "fail_pad\n";
echo function_exists('mb_trim') ? "fail_trim\n" : "ok_trim\n";
--EXPECT--
ok_uc
ok_lc
ok_pad
ok_trim
