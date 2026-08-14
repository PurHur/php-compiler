--TEST--
Language: enum `in` operator is a Parse error — php-src has no `in` (#31158, re-#4682)
--FILE--
<?php
enum E: string
{
    case A = 'a';
    case B = 'b';
}

$e = E::A;
var_dump($e in [E::A, E::B]);
var_dump($e in [E::B]);
var_dump(E::B in [E::A, E::B]);
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected identifier "in", expecting "," or ";" in %s on line %d
