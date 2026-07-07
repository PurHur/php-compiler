--TEST--
stdlib array_slice() on enum case arrays preserves objects not NULL (#5554)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A, E::B];
$s = array_slice($a, 0);
echo $s[0] instanceof E ? 'enum0' : 'null0', "\n";
echo $s[1] instanceof E ? 'enum1' : 'null1', "\n";
echo $s[0]->name, "\n";
echo $s[1]->name, "\n";
--EXPECT--
enum0
enum1
A
B
