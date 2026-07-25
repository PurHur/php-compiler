--TEST--
stdlib extension_loaded('gmp') withheld on reference profile (#22860, ext/gmp/gmp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', extension_loaded('gmp') ? '1' : '0', "\n";
echo 'in_list=', in_array('gmp', get_loaded_extensions(), true) ? '1' : '0', "\n";
echo 'class=', class_exists('GMP', false) ? '1' : '0', "\n";
echo 'gmp_add=', function_exists('gmp_add') ? '1' : '0', "\n";
echo 'GMP_MSW_FIRST=', defined('GMP_MSW_FIRST') ? '1' : '0', "\n";
--EXPECT--
loaded=0
in_list=0
class=0
gmp_add=0
GMP_MSW_FIRST=0
