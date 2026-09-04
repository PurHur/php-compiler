<?php
// asin()/acos() via libm asin(3)/acos(3) must match Zend (#36386).
// LLVM 9 has no llvm.asin.f64 / llvm.acos.f64.
// @differential-repeat: 3
echo asin(0.0), "\n";
echo asin(1.0), "\n";
echo asin(-1.0), "\n";
echo asin(0.5), "\n";
echo acos(0.0), "\n";
echo acos(1.0), "\n";
echo acos(-1.0), "\n";
echo acos(0.5), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += asin(0.25) + acos(0.25);
}
echo $s, "\n";
