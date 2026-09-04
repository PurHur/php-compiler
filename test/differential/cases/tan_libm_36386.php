<?php
// tan() via libm tan(3) must match Zend (#36386). LLVM 9 has no llvm.tan.f64.
// @differential-repeat: 3
echo tan(0.0), "\n";
echo tan(1.0), "\n";
echo tan(M_PI / 4.0), "\n";
echo tan(0.5), "\n";
echo tan(2.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += tan(0.25);
}
echo $s, "\n";
