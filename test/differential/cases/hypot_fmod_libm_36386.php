<?php
// hypot()/fmod() via libm hypot(3)/fmod(3) must match Zend (#36386).
// LLVM 9 has no llvm.hypot.f64 / llvm.fmod.f64.
// @differential-repeat: 3
echo hypot(3.0, 4.0), "\n";
echo hypot(0.0, 5.0), "\n";
echo hypot(5.0, 12.0), "\n";
echo fmod(5.5, 2.0), "\n";
echo fmod(-1.5, 1.2), "\n";
echo fmod(5.7, 1.3), "\n";
echo fmod(-7.0, 3.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += hypot(3.0, 4.0) + fmod(3.0, 4.0);
}
echo $s, "\n";
