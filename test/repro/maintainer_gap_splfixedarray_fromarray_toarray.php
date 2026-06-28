<?php

declare(strict_types=1);

// Repro for #13066 — SplFixedArray::fromArray()/toArray() (ext/spl/spl_fixedarray.c).

if (!method_exists(SplFixedArray::class, 'fromArray')) {
    echo 'fail: SplFixedArray::fromArray() missing', PHP_EOL;
    exit(1);
}

$fa = new SplFixedArray(2);
if (!method_exists($fa, 'toArray')) {
    echo 'fail: SplFixedArray::toArray() missing', PHP_EOL;
    exit(1);
}

$from = SplFixedArray::fromArray([10, 20, 30], false);
if (count($from) !== 3) {
    echo 'fail: fromArray(false) count expected 3, got ', count($from), PHP_EOL;
    exit(1);
}
if ($from[0] !== 10 || $from[1] !== 20 || $from[2] !== 30) {
    echo 'fail: fromArray(false) values mismatch', PHP_EOL;
    exit(1);
}

$roundTrip = SplFixedArray::fromArray([1, 2, 3]);
$exported = $roundTrip->toArray();
if ($exported !== [1, 2, 3]) {
    echo 'fail: toArray round-trip expected [1,2,3], got ', var_export($exported, true), PHP_EOL;
    exit(1);
}

echo 'ok', PHP_EOL;
