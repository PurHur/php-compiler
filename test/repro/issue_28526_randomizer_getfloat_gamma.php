<?php

/**
 * #28526 — Randomizer::getFloat ClosedOpen/OpenClosed must match Zend γ-section
 * (range64 power-of-two mask must cover full umax, not truncated 32-bit).
 */
error_reporting(E_ALL);
$seed = 123;
foreach ([
    ['ClosedOpen', null],
    ['OpenClosed', Random\IntervalBoundary::OpenClosed],
    ['OpenOpen', Random\IntervalBoundary::OpenOpen],
    ['ClosedClosed', Random\IntervalBoundary::ClosedClosed],
] as [$label, $boundary]) {
    $r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
    $v = null === $boundary ? $r->getFloat(0.0, 1.0) : $r->getFloat(0.0, 1.0, $boundary);
    echo $label, '=', $v, "\n";
}
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'nextFloat=', $r->nextFloat(), "\n";
$r = new Random\Randomizer(new Random\Engine\Mt19937($seed));
echo 'MtClosedOpen=', $r->getFloat(0.0, 1.0), "\n";
