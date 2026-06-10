--TEST--
stdlib in_array() loose — enum cases must not match backing scalars (#5592, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

echo in_array(1, [E::A], false) ? 'y' : 'n', "\n";
echo in_array(E::A, [1], false) ? 'y' : 'n', "\n";
echo in_array('1', [E::A], false) ? 'y' : 'n', "\n";
echo in_array(E::A, [E::A], true) ? 'y' : 'n', "\n";
--EXPECT--
n
n
n
y
