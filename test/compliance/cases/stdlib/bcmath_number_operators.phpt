--TEST--
stdlib BcMath\Number arithmetic operators (+ - * / % **) — do_operation (#20648)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
use BcMath\Number;

$a = new Number('1.5');
$b = new Number('2.5');
$c = new Number('10');
$d = new Number('3');

$sum = $a + $b;
echo (string) $sum, '/', $sum->scale, "\n";
$diff = $a - $b;
echo (string) $diff, '/', $diff->scale, "\n";
$prod = $a * $b;
echo (string) $prod, '/', $prod->scale, "\n";
$quot = $c / $d;
echo (string) $quot, '/', $quot->scale, "\n";
$rem = $c % $d;
echo (string) $rem, '/', $rem->scale, "\n";
$pow = $d ** new Number('2');
echo (string) $pow, '/', $pow->scale, "\n";
echo (string) ($a + 2), "\n";
echo (string) (2 + $a), "\n";
echo (string) (-$a), "\n";
echo (int) ($a < $b), (int) ($a == new Number('1.5')), (int) ($a === new Number('1.5')), "\n";
echo (string) $a->add($b), "\n";
try {
    echo (string) ($a + null);
} catch (TypeError $e) {
    echo "null TypeError\n";
}
// Operators inside try/catch and after an unrelated catch (#21266).
try {
    echo 'try+', (string) ($a + $b), "\n";
} catch (Throwable $e) {
    echo 'tryEX=', $e->getMessage(), "\n";
}
try {
    throw new Exception('unrelated');
} catch (Throwable $e) {
    echo 'caught=', $e->getMessage(), "\n";
}
echo 'after+', (string) ($a + $b), "\n";
--EXPECT--
4.0/1
-1.0/1
3.75/2
3.3333333333/10
1/0
9/0
3.5
3.5
-1.5
110
4.0
null TypeError
try+4.0
caught=unrelated
after+4.0
