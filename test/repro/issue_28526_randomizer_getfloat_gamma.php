<?php

/**
 * #28526 — Randomizer::getFloat ClosedOpen/OpenClosed must match Zend γ-section
 * (range64 power-of-two mask must cover full umax, not truncated 32-bit).
 */
error_reporting(E_ALL);
$seed = 123;
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'ClosedOpen=', $r->getFloat(0.0, 1.0), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'OpenClosed=', $r->getFloat(0.0, 1.0, Random\IntervalBoundary::OpenClosed), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'OpenOpen=', $r->getFloat(0.0, 1.0, Random\IntervalBoundary::OpenOpen), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'ClosedClosed=', $r->getFloat(0.0, 1.0, Random\IntervalBoundary::ClosedClosed), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo 'nextFloat=', $r->nextFloat(), "\n";
$r = new Random\Randomizer(new Random\Engine\Mt19937($seed));
echo 'MtClosedOpen=', $r->getFloat(0.0, 1.0), "\n";
