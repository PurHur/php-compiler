--TEST--
array_push()/array_unshift() preserve enum case objects (#5593)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A];
array_push($a, E::B);
echo $a[0] instanceof E ? "push0\n" : "push0_bad\n";
echo $a[1] instanceof E ? "push1\n" : "push1_bad\n";
$b = [E::B];
array_unshift($b, E::A);
echo $b[0] instanceof E ? "unshift0\n" : "unshift0_bad\n";
echo $b[1] instanceof E ? "unshift1\n" : "unshift1_bad\n";
--EXPECT--
push0
push1
unshift0
unshift1
