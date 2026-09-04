<?php
// log()/log10() via llvm.log.f64 / llvm.log10.f64 must match Zend (#36386).
// @differential-repeat: 3
echo log(1.0), "\n";
echo log(M_E), "\n";
echo log(0.5), "\n";
echo log(2.0), "\n";
echo log10(1.0), "\n";
echo log10(10.0), "\n";
echo log10(100.0), "\n";
echo log(100.0, 10.0), "\n";
echo log(8.0, 2.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += log(2.0);
    $s += log10(2.0);
}
echo $s, "\n";
