--TEST--
stdlib array_replace() JIT preserves enum case objects (#5597)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A];
$b = [E::B];
$r = array_replace($a, $b);
echo $r[0]->name, ($r[0] instanceof E ? '' : '!'), "\n";
--EXPECT--
B
