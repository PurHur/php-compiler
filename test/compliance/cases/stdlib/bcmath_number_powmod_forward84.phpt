--TEST--
stdlib BcMath\Number::powmod() — modular exponentiation (#24612, ext/bcmath)
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
echo method_exists($n, 'powmod') ? "has_powmod\n" : "missing_powmod\n";

$p = $n->powmod('3', '7');
echo $p->value, ' s=', $p->scale, "\n";

$p2 = $n->powmod('3', '7', 2);
echo $p2->value, ' s=', $p2->scale, "\n";

echo (new Number('1.0'))->powmod('2', '7')->value, "\n";
echo (new Number('-10'))->powmod('3', '7')->value, "\n";
echo (new Number('10'))->powmod('0', '7')->value, "\n";

try {
    (new Number('1.5'))->powmod('2', '7');
    echo "no_base_frac\n";
} catch (ValueError $e) {
    echo 'base_frac:', $e->getMessage(), "\n";
}

try {
    (new Number('10'))->powmod('2.5', '7');
    echo "no_exp_frac\n";
} catch (ValueError $e) {
    echo 'exp_frac:', $e->getMessage(), "\n";
}

try {
    (new Number('10'))->powmod('2', '7.5');
    echo "no_mod_frac\n";
} catch (ValueError $e) {
    echo 'mod_frac:', $e->getMessage(), "\n";
}

try {
    (new Number('10'))->powmod('-1', '7');
    echo "no_neg_exp\n";
} catch (ValueError $e) {
    echo 'neg_exp:', $e->getMessage(), "\n";
}

try {
    (new Number('10'))->powmod('3', '0');
    echo "no_mod0\n";
} catch (DivisionByZeroError $e) {
    echo 'mod0:', $e->getMessage(), "\n";
}
--EXPECT--
has_powmod
6 s=0
6.00 s=2
1
-6
1
base_frac:Base number cannot have a fractional part
exp_frac:BcMath\Number::powmod(): Argument #1 ($exponent) cannot have a fractional part
mod_frac:BcMath\Number::powmod(): Argument #2 ($modulus) cannot have a fractional part
neg_exp:BcMath\Number::powmod(): Argument #1 ($exponent) must be greater than or equal to 0
mod0:Modulo by zero
