--TEST--
named arguments after call-time unpack (PHP 8.1+, zend_compile.c / #4663)
--FILE--
<?php
function demo(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$rest = ['b' => 2, 'c' => 3];
echo demo(...$rest, a: 1), "\n";
--EXPECT--
6
