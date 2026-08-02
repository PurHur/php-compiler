--TEST--
stdlib bcpow/bcsqrt Zend stub names num/exponent (PROFILE=8.4, #26145)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('bcpow');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
echo bcpow(num: '2', exponent: '3'), "\n";
try {
    echo bcpow(x: '2', y: '3'), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
$r2 = new ReflectionFunction('bcsqrt');
echo 'bcsqrt=', implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), "\n";
echo bcsqrt(num: '9'), "\n";
try {
    echo bcsqrt(operand: '9'), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
num,exponent,scale
8
Unknown named parameter $x
bcsqrt=num,scale
3
Unknown named parameter $operand
