--TEST--
stdlib BcMath\Number::pow/mod/sqrt/floor/ceil/round — OOP surface (#19582)
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}
if (!method_exists(Number::class, 'pow')) {
    echo "skip: BcMath\\Number::pow missing\n";
    exit(0);
}

$n = new Number('2');
echo (string) $n->pow('8'), "\n";
echo (string) (new Number('10'))->mod('3'), "\n";
echo (string) (new Number('9'))->sqrt(), "\n";
echo (string) (new Number('1.5'))->floor(), "\n";
echo (string) (new Number('1.5'))->ceil(), "\n";
echo (string) (new Number('1.5'))->round(0), "\n";
echo bcpow('2', '8') === (string) $n->pow('8') ? "pow matches bcpow\n" : "pow mismatch\n";
echo bcmod('10', '3') === (string) (new Number('10'))->mod('3') ? "mod matches bcmod\n" : "mod mismatch\n";
echo bcfloor('1.5') === (string) (new Number('1.5'))->floor() ? "floor matches bcfloor\n" : "floor mismatch\n";
echo bcceil('1.5') === (string) (new Number('1.5'))->ceil() ? "ceil matches bcceil\n" : "ceil mismatch\n";
echo bcround('1.5', 0) === (string) (new Number('1.5'))->round(0) ? "round matches bcround\n" : "round mismatch\n";
--EXPECT--
256
1
3
1
2
2
pow matches bcpow
mod matches bcmod
floor matches bcfloor
ceil matches bcceil
round matches bcround
