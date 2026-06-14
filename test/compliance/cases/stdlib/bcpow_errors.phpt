--TEST--
stdlib bcpow()/bcmod()/bcsqrt() — php-src-strict errors (ext/bcmath/bcmath.c, #6042)
--FILE--
<?php
try {
    bcpow('2', '-1');
    echo "negative expo\n";
} catch (ValueError $e) {
    echo "negative expo\n";
}
try {
    bcpow('0', '0');
    echo "zero pow\n";
} catch (ValueError $e) {
    echo "zero pow\n";
}
try {
    bcmod('10', '0');
    echo "zero mod\n";
} catch (DivisionByZeroError $e) {
    echo "zero mod\n";
}
try {
    bcsqrt('-1');
    echo "neg sqrt\n";
} catch (ValueError $e) {
    echo "neg sqrt\n";
}
echo "ok\n";
--EXPECT--
negative expo
zero pow
zero mod
neg sqrt
ok
