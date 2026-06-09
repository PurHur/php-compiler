--TEST--
stdlib rsort() preserves backed enum case objects (#6150, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
rsort($a);
echo $a[0]->name, ',', $a[1]->name, "\n";
--EXPECT--
B,A
