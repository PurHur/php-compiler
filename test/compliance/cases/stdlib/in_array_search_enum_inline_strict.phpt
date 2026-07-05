--TEST--
stdlib in_array()/array_search() — enum needle + inline haystack + strict after in_array var_dump (#9390, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

var_dump(in_array(E::A, [E::A], true));
var_dump(array_search(E::A, [E::A], true));
var_dump(in_array(E::A, [E::A, E::B], true));
var_dump(array_search(E::A, [E::A, E::B], true));
--EXPECT--
bool(true)
int(0)
bool(true)
int(0)
