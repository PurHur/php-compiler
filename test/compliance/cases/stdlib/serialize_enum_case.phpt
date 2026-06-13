--TEST--
Stdlib: serialize()/unserialize() enum case round-trip (#5739, #6131, ext/standard/var.c)
--FILE--
<?php
enum U { case A; }
enum I: int { case N = 42; }
enum S: string { case X = 'hi'; }

$u = U::A;
$i = I::N;
$s = S::X;

echo 'unit_ser: ', serialize($u), "\n";
echo 'unit_same: ', (unserialize(serialize($u)) === $u) ? '1' : '0', "\n";
echo 'backed_int_ser: ', serialize($i), "\n";
echo 'backed_int_same: ', (unserialize(serialize($i)) === $i) ? '1' : '0', "\n";
echo 'backed_str_ser: ', serialize($s), "\n";
echo 'backed_str_same: ', (unserialize(serialize($s)) === $s) ? '1' : '0', "\n";
echo 'array_ser: ', serialize([U::A, I::N]), "\n";
--EXPECT--
unit_ser: E:3:"U:A";
unit_same: 1
backed_int_ser: E:3:"I:N";
backed_int_same: 1
backed_str_ser: E:3:"S:X";
backed_str_same: 1
array_ser: a:2:{i:0;E:3:"U:A";i:1;E:3:"I:N";}
