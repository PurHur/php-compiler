--TEST--
JIT: array_key_first()/array_key_last() on enum case lists preserve object identity (#5685)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$arr = [E::A, E::B];
echo array_key_last($arr), "\n";
echo array_key_first($arr), "\n";
$arr2 = [E::A, E::B];
end($arr2);
echo current($arr2) === E::B ? "end-identity\n" : "end-scalar\n";
echo $arr[0] === E::A ? "val0-identity\n" : "val0-scalar\n";
echo $arr[1] === E::B ? "val1-identity\n" : "val1-scalar\n";
--EXPECT--
1
0
end-identity
val0-identity
val1-identity
