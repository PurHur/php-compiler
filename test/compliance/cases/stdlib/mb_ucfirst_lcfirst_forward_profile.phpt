--TEST--
stdlib mb_ucfirst()/mb_lcfirst() — function_exists on PHP_COMPILER_PROFILE=8.4 (#17609, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('mb_ucfirst') ? '1' : '0';
echo "\n";
echo function_exists('mb_lcfirst') ? '1' : '0';
echo "\n";
echo mb_ucfirst('über'), "\n";
echo mb_lcfirst('Über'), "\n";
--EXPECT--
1
1
Über
über
