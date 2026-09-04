<?php
// sinh()/cosh()/tanh() via libm sinh(3)/cosh(3)/tanh(3) must match Zend (#36386).
// LLVM 9 has no llvm.sinh.f64 / llvm.cosh.f64 / llvm.tanh.f64.
// @differential-repeat: 3
echo sinh(0.0), "\n";
echo sinh(1.0), "\n";
echo sinh(-1.0), "\n";
echo cosh(0.0), "\n";
echo cosh(1.0), "\n";
echo tanh(0.0), "\n";
echo tanh(1.0), "\n";
echo tanh(0.5), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += sinh(0.25) + cosh(0.25) + tanh(0.25);
}
echo $s, "\n";
