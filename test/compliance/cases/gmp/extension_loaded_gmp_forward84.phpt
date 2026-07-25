--TEST--
gmp surface present under PHP_COMPILER_PROFILE=8.4 (#22860, ext/gmp/gmp.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', extension_loaded('gmp') ? '1' : '0', "\n";
echo 'gmp_add=', function_exists('gmp_add') ? '1' : '0', "\n";
echo 'class=', class_exists('GMP', false) ? '1' : '0', "\n";
echo gmp_strval(gmp_add('2', '3')), "\n";
--EXPECT--
loaded=1
gmp_add=1
class=1
5
