--TEST--
stdlib abs/ceil/floor/round/sqrt — backed enum case TypeError (#5613, ext/standard/math.c)
--FILE--
<?php
enum E: int { case N = 5; }
$c = E::N;
foreach ([
    ['abs', $c],
    ['ceil', $c],
    ['floor', $c],
    ['round', $c],
    ['sqrt', $c],
] as [$fn, $arg]) {
    try {
        $fn($arg);
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
abs(): Argument #1 ($num) must be of type int|float, E given
ceil(): Argument #1 ($num) must be of type int|float, E given
floor(): Argument #1 ($num) must be of type int|float, E given
round(): Argument #1 ($num) must be of type int|float, E given
sqrt(): Argument #1 ($num) must be of type float, E given
