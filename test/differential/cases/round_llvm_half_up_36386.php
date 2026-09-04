<?php
// round() places=0 HALF_UP via llvm.round.f64 must match Zend (#36386).
// Float formals avoid host fold so AOT hits llvm.round.f64.
// @differential-repeat: 3
function r(float $x): float
{
    return round($x);
}
function r0(float $x): float
{
    return round($x, 0, PHP_ROUND_HALF_UP);
}
echo r(1.5), "\n";
echo r(2.5), "\n";
echo r(-1.5), "\n";
echo r(-2.5), "\n";
echo r(0.5), "\n";
echo r(-0.5), "\n";
echo r(1.4), "\n";
echo r(1.6), "\n";
echo r0(3.0), "\n";
echo r0(-2.5), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += r(1.5);
}
echo $s, "\n";
