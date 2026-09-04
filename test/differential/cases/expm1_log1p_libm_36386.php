<?php
// expm1()/log1p() via libm expm1(3)/log1p(3) must match Zend (#36386).
// LLVM 9 has no llvm.expm1.f64 / llvm.log1p.f64.
// @differential-repeat: 3
echo expm1(0.0), "\n";
echo expm1(1.0), "\n";
echo expm1(-1.0), "\n";
echo log1p(0.0), "\n";
echo log1p(1.0), "\n";
echo log1p(-0.5), "\n";
echo log1p(0.5), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += expm1(0.25) + log1p(0.25);
}
echo $s, "\n";
