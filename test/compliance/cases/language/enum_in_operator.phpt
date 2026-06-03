--TEST--
Language: enum `in` operator — backed enum case membership (#4682)
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
--EXPECT--
bool(true)
bool(false)
bool(true)
