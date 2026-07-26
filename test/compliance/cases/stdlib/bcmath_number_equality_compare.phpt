--TEST--
stdlib BcMath\Number == shares compare handler with <=> (php-src bcmath_number_compare, #23602)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
use BcMath\Number;

$a = new Number('1.50');
$b = new Number('1.5');
echo (int) ($a == $b), (int) (($a <=> $b) === 0), (int) ($a->compare($b) === 0), "\n";
echo (int) ((new Number('2')) == 2), (int) ((new Number('2')) != 2), "\n";
echo (int) ((new Number('2')) == '2'), (int) ('2' == new Number('2')), "\n";
echo (int) ((new Number('1.50')) === new Number('1.5')), "\n";
echo (int) ($a < $b), (int) ($a <= $b), (int) ($a > new Number('1')), "\n";
--EXPECT--
111
10
11
0
011
