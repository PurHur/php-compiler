<?php

declare(strict_types=1);

// Repro for #13086 — SplFixedArray::getSize()/setSize() (ext/spl/spl_fixedarray.c).

$fa = new SplFixedArray(2);
if (!method_exists($fa, 'getSize') || !method_exists($fa, 'setSize')) {
    echo 'fail: getSize/setSize missing', PHP_EOL;
    exit(1);
}
if (2 !== $fa->getSize() || 2 !== count($fa)) {
    echo 'fail: initial getSize/count expected 2, got ', $fa->getSize(), '/', count($fa), PHP_EOL;
    exit(1);
}

$fa[0] = 10;
$fa[1] = 20;
$fa->setSize(4);
if (4 !== $fa->getSize() || 4 !== count($fa)) {
    echo 'fail: after grow getSize/count expected 4', PHP_EOL;
    exit(1);
}
if (10 !== $fa[0] || 20 !== $fa[1] || null !== $fa[2] || null !== $fa[3]) {
    echo 'fail: grow should preserve values and null-fill new slots', PHP_EOL;
    exit(1);
}

$from = SplFixedArray::fromArray([1, 2, 3, 4, 5]);
$from->setSize(2);
if (2 !== $from->getSize() || [1, 2] !== $from->toArray()) {
    echo 'fail: shrink expected [1,2], got ', var_export($from->toArray(), true), PHP_EOL;
    exit(1);
}

try {
    $fa->setSize(-1);
    echo 'fail: setSize(-1) should throw ValueError', PHP_EOL;
    exit(1);
} catch (ValueError) {
}

echo 'ok', PHP_EOL;
