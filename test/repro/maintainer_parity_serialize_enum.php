<?php
enum U { case A; }
enum I: int { case N = 42; }

$u = U::A;
$i = I::N;

echo 'unit_ser: ', serialize($u), "\n";
echo 'unit_same: ', (unserialize(serialize($u)) === $u) ? '1' : '0', "\n";
echo 'backed_int_ser: ', serialize($i), "\n";
echo 'backed_int_same: ', (unserialize(serialize($i)) === $i) ? '1' : '0', "\n";
