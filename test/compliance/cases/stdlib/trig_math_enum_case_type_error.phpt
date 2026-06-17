--TEST--
stdlib trig/hyperbolic math — backed enum case TypeError (#5883, ext/standard/math.c)
--FILE--
<?php
enum E: int { case A = 1; }
$c = E::A;
foreach ([
    ['sin', $c],
    ['cos', $c],
    ['tan', $c],
    ['asin', $c],
    ['acos', $c],
    ['atan', $c],
    ['sinh', $c],
    ['cosh', $c],
    ['tanh', $c],
    ['acosh', $c],
    ['asinh', $c],
    ['atanh', $c],
    ['expm1', $c],
    ['log1p', $c],
    ['deg2rad', $c],
    ['rad2deg', $c],
] as [$fn, $arg]) {
    try {
        $fn($arg);
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
try {
    atan2(E::A, 2.0);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hypot(E::A, 2.0);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    fmod(E::A, 2.0);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sin(): Argument #1 ($num) must be of type float, E given
cos(): Argument #1 ($num) must be of type float, E given
tan(): Argument #1 ($num) must be of type float, E given
asin(): Argument #1 ($num) must be of type float, E given
acos(): Argument #1 ($num) must be of type float, E given
atan(): Argument #1 ($num) must be of type float, E given
sinh(): Argument #1 ($num) must be of type float, E given
cosh(): Argument #1 ($num) must be of type float, E given
tanh(): Argument #1 ($num) must be of type float, E given
acosh(): Argument #1 ($num) must be of type float, E given
asinh(): Argument #1 ($num) must be of type float, E given
atanh(): Argument #1 ($num) must be of type float, E given
expm1(): Argument #1 ($num) must be of type float, E given
log1p(): Argument #1 ($num) must be of type float, E given
deg2rad(): Argument #1 ($num) must be of type float, E given
rad2deg(): Argument #1 ($num) must be of type float, E given
atan2(): Argument #1 ($y) must be of type float, E given
hypot(): Argument #1 ($x) must be of type float, E given
fmod(): Argument #1 ($num1) must be of type float, E given
