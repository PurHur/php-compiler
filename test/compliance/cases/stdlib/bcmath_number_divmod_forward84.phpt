--TEST--
stdlib BcMath\Number::divmod() — quotient+remainder Number pair (#24611, ext/bcmath)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

$n = new Number('10');
echo method_exists($n, 'divmod') ? "has_divmod\n" : "missing_divmod\n";

$r = $n->divmod('3');
echo $r[0]->value, ',', $r[1]->value, ' s=', $r[0]->scale, ',', $r[1]->scale, "\n";

$n2 = new Number('10.5');
$r2 = $n2->divmod('2.5');
echo $r2[0]->value, ',', $r2[1]->value, ' s=', $r2[0]->scale, ',', $r2[1]->scale, "\n";

$r3 = $n2->divmod('2.5', 2);
echo $r3[0]->value, ',', $r3[1]->value, ' s=', $r3[0]->scale, ',', $r3[1]->scale, "\n";

$r4 = (new Number('10.00'))->divmod('5');
echo $r4[0]->value, ',', $r4[1]->value, ' s=', $r4[0]->scale, ',', $r4[1]->scale, "\n";

try {
    $n->divmod('0');
    echo "no_div0\n";
} catch (DivisionByZeroError $e) {
    echo 'div0:', $e->getMessage(), "\n";
}
--EXPECT--
has_divmod
3,1 s=0,0
4,0.5 s=0,1
4,0.50 s=0,2
2,0.00 s=0,2
div0:Division by zero
