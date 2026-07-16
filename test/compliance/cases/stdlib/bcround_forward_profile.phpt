--TEST--
stdlib bcround() — advertised on PHP_COMPILER_PROFILE=8.4 (#16709, #19608, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('bcround') ? "exists\n" : "missing\n";
echo bcround('1.234', 2), "\n";
echo function_exists('bcadd') ? "bcadd_exists\n" : "bcadd_phantom\n";
--EXPECT--
exists
1.23
bcadd_exists
