--TEST--
Stdlib: max()/min() with backed enum case operands (#5570, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$m = max(E::A, E::B);
$n = min(E::A, E::B);
echo $m->name, ' ', $m->value, "\n";
echo $n->name, ' ', $n->value, "\n";
echo ($m === E::B) ? '1' : '0', "\n";
echo ($n === E::A) ? '1' : '0', "\n";
$t = max([E::B, E::A]);
echo $t->name, "\n";
$u = max([E::A, E::B]);
echo $u->name, "\n";
$v = max(E::A, E::B, E::A);
echo $v->name, "\n";
--EXPECT--
B 2
A 1
1
1
B
A
A
