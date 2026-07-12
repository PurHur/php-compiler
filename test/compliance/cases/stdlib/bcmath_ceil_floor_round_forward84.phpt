--TEST--
Stdlib: bcceil()/bcfloor()/bcround() function_exists on PHP 8.4 forward profile (#17645)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('bcceil'), "\n";
echo (int) function_exists('bcfloor'), "\n";
echo (int) function_exists('bcround'), "\n";
echo bcceil('1.2'), "\n";
echo bcfloor('1.9'), "\n";
echo bcround('1.5', 0), "\n";
--EXPECT--
1
1
1
2
1
2
