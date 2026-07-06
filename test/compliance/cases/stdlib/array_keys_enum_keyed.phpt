--TEST--
Stdlib: array_keys()/array_key_first()/array_key_last() on enum-keyed arrays (#9871, array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [];
$a[E::A] = 'x';
$a[E::B] = 'y';
$keys = array_keys($a);
echo ($keys[0] === E::A && $keys[1] === E::B) ? "keys-identity\n" : "keys-scalar\n";
echo (array_key_first($a) === E::A) ? "first-ok\n" : "first-bad\n";
echo (array_key_last($a) === E::B) ? "last-ok\n" : "last-bad\n";
--EXPECT--
keys-identity
first-ok
last-ok
