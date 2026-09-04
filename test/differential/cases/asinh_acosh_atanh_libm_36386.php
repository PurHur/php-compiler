<?php
// asinh()/acosh()/atanh() via libm asinh(3)/acosh(3)/atanh(3) must match Zend (#36386).
// LLVM 9 has no llvm.asinh.f64 / llvm.acosh.f64 / llvm.atanh.f64.
// @differential-repeat: 3
echo asinh(0.0), "\n";
echo asinh(1.0), "\n";
echo asinh(-1.0), "\n";
echo acosh(1.0), "\n";
echo acosh(2.0), "\n";
echo atanh(0.0), "\n";
echo atanh(0.5), "\n";
echo atanh(-0.5), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += asinh(0.25) + acosh(1.25) + atanh(0.25);
}
echo $s, "\n";
