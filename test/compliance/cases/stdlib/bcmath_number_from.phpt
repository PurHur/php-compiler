--TEST--
stdlib BcMath\Number::from() — static factory (ext/bcmath/bcmath.c, #16814)
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

if (!method_exists(Number::class, 'from')) {
    echo "skip: BcMath\\Number::from missing\n";
    exit(0);
}

echo (string) Number::from('1.50'), "\n";
echo (string) Number::from(100), "\n";
echo Number::from('-2.5')->scale, "\n";
--EXPECT--
1.50
100
1
