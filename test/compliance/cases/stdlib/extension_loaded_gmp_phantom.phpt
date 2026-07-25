--TEST--
stdlib extension_loaded('gmp') false without host php-gmp / forward profile (#22860, ext/gmp/gmp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('gmp'), "\n";
echo 'in_list=', (int) in_array('gmp', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('gmp')), "\n";
echo 'gmp_add=', (int) function_exists('gmp_add'), "\n";
echo 'gmp_init=', (int) function_exists('gmp_init'), "\n";
echo 'GMP=', (int) class_exists('GMP', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
gmp_add=0
gmp_init=0
GMP=0
