--TEST--
stdlib trig/hyperbolic math JIT — backed enum case TypeError (#5883)
--FILE--
<?php
enum E: int { case A = 1; }
$c = E::A;
foreach (['sin', 'cos', 'deg2rad', 'fmod'] as $fn) {
    try {
        $fn === 'fmod' ? $fn($c, 2.0) : $fn($c);
        echo "$fn uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
sin(): Argument #1 ($num) must be of type float, E given
cos(): Argument #1 ($num) must be of type float, E given
deg2rad(): Argument #1 ($num) must be of type float, E given
fmod(): Argument #1 ($num1) must be of type float, E given
