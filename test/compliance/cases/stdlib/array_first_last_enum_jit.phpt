--TEST--
JIT: array_first()/array_last() on enum case lists preserve object identity (#6154)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$arr = [E::A, E::B];
echo array_first($arr) === E::A ? "first-identity\n" : "first-scalar\n";
echo array_last($arr) === E::B ? "last-identity\n" : "last-scalar\n";
echo $arr[0] === E::A ? "val0-identity\n" : "val0-scalar\n";
echo $arr[1] === E::B ? "val1-identity\n" : "val1-scalar\n";
--EXPECT--
first-identity
last-identity
val0-identity
val1-identity
