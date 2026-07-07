<?php

declare(strict_types=1);

if (!enum_exists('Random\IntervalBoundary', false)) {
    fwrite(STDERR, "fail: Random\\IntervalBoundary enum missing\n");
    exit(1);
}

if (!method_exists(Random\Randomizer::class, 'getFloat')) {
    fwrite(STDERR, "fail: Random\\Randomizer::getFloat() missing\n");
    exit(1);
}

if (!method_exists(Random\Randomizer::class, 'nextFloat')) {
    fwrite(STDERR, "fail: Random\\Randomizer::nextFloat() missing\n");
    exit(1);
}

$engine = new Random\Engine\Mt19937(42);
$randomizer = new Random\Randomizer($engine);

$next = $randomizer->nextFloat();
if (!\is_float($next) || $next < 0.0 || $next >= 1.0) {
    fwrite(STDERR, "fail: nextFloat() out of [0,1) range: {$next}\n");
    exit(1);
}

$got = $randomizer->getFloat(0.0, 1.0, Random\IntervalBoundary::ClosedOpen);
if (!\is_float($got) || $got < 0.0 || $got >= 1.0) {
    fwrite(STDERR, "fail: getFloat(ClosedOpen) out of [0,1) range: {$got}\n");
    exit(1);
}

echo "ok\n";
