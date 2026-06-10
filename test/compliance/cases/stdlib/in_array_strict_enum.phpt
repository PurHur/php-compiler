--TEST--
stdlib in_array() strict — enum cases must not match backing scalars (#5851, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

echo in_array(1, [E::A, E::B], true) ? 'y' : 'n', "\n";
echo in_array(E::A, [1, 2], true) ? 'y' : 'n', "\n";
echo in_array(E::A, [E::A], true) ? 'y' : 'n', "\n";
echo in_array(E::A, [E::A, E::B], true) ? 'y' : 'n', "\n";
--EXPECT--
n
n
y
y
