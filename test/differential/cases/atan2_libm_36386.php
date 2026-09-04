<?php
// atan2() via libm atan2(3) must match Zend (#36386).
// LLVM 9 has no llvm.atan2.f64.
// @differential-repeat: 3
echo atan2(0.0, 1.0), "\n";
echo atan2(1.0, 1.0), "\n";
echo atan2(-1.0, 1.0), "\n";
echo atan2(1.0, -1.0), "\n";
echo atan2(-1.0, -1.0), "\n";
echo atan2(1.0, 0.0), "\n";
echo atan2(3.0, 4.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += atan2(3.0, 4.0);
}
echo $s, "\n";
