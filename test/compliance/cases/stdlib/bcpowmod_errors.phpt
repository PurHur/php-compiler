--TEST--
stdlib bcpowmod() — php-src-strict errors (ext/bcmath/bcmath.c, #6976)
--FILE--
<?php
try {
    bcpowmod('2', '1.5', '10');
    echo "no fractional expo\n";
} catch (ValueError $e) {
    echo "fractional expo\n";
}
try {
    bcpowmod('2', '-1', '10');
    echo "negative expo\n";
} catch (ValueError $e) {
    echo "negative expo\n";
}
try {
    bcpowmod('2', '1', '0');
    echo "zero mod\n";
} catch (DivisionByZeroError $e) {
    echo "zero mod\n";
}
echo "ok\n";
--EXPECT--
fractional expo
negative expo
zero mod
ok
