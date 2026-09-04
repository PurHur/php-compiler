<?php
// exp() via llvm.exp.f64 must match Zend (#36386).
// @differential-repeat: 3
echo exp(0.0), "\n";
echo exp(1.0), "\n";
echo exp(-1.0), "\n";
echo exp(0.5), "\n";
echo exp(2.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += exp(0.25);
}
echo $s, "\n";
