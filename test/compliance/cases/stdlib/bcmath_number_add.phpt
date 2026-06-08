--TEST--
stdlib BcMath\Number::add() — OOP bcmath (ext/bcmath/bcmath.c, #7220)
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

$a = new Number('1.234');
$b = new Number('5');
echo $a->add($b, 2)->value, "\n";
echo (string) $a->add($b, 2), "\n";
echo $a->compare($b), "\n";
echo $a instanceof Number ? "instanceof ok\n" : "instanceof fail\n";
--EXPECT--
6.23
6.23
-1
instanceof ok
