<?php
// sin()/cos() via llvm.sin.f64 / llvm.cos.f64 must match Zend (#36386).
// @differential-repeat: 3
echo sin(0.0), "\n";
echo cos(0.0), "\n";
echo sin(1.0), "\n";
echo cos(1.0), "\n";
echo sin(M_PI / 2.0), "\n";
echo cos(M_PI / 3.0), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += sin(0.5) + cos(0.25);
}
echo $s, "\n";
