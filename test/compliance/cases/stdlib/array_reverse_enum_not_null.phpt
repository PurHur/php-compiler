--TEST--
stdlib array_reverse() on enum case arrays preserves objects not NULL (#9346, #5554)
--FILE--
<?php
enum E: string { case A = 'a'; case B = 'b'; }
$a = [E::A, E::B];
$r = array_reverse($a);
echo $r[0] instanceof E ? 'enum0' : 'null0', "\n";
echo $r[1] instanceof E ? 'enum1' : 'null1', "\n";
echo $r[0]->name, "\n";
echo $r[1]->name, "\n";
--EXPECT--
enum0
enum1
B
A
