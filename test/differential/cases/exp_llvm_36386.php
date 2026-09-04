<?php
// exp() via llvm.exp.f64 must match Zend (#36386).
// Fixed-decimal print avoids PG(precision) stringify drift (VmZendDoubleString vs zend_dtoa).
// @differential-repeat: 3
printf("%.12f\n", exp(0.0));
printf("%.12f\n", exp(1.0));
printf("%.12f\n", exp(-1.0));
printf("%.12f\n", exp(0.5));
printf("%.12f\n", exp(2.0));
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += exp(0.25);
}
printf("%.12f\n", $s);
