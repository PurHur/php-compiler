<?php
// pow() float arm via llvm.pow.f64 must match Zend (#36386).
// (fpow() is withheld on the default 8.4.0-dev profile; pow shares MathFpow.)
// @differential-repeat: 3
echo pow(2.0, 3.0), "\n";
echo pow(4.0, 0.5), "\n";
echo pow(2.0, -3.0), "\n";
echo pow(2.5, 1.5), "\n";
echo pow(10.0, 0.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += pow(2.0, 0.5);
}
echo $s, "\n";
