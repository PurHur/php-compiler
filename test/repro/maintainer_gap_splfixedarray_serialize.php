<?php

declare(strict_types=1);

/**
 * Issue #13179 — SplFixedArray::__serialize()/__unserialize() (ext/spl/spl_fixedarray.c).
 */

$a = SplFixedArray::fromArray([10, 20, 30]);

if (!method_exists($a, '__serialize') || !method_exists($a, '__unserialize')) {
    echo "fail: __serialize/__unserialize not registered\n";
    exit(1);
}

$wire = serialize($a);
$b = unserialize($wire);
if (!($b instanceof SplFixedArray)) {
    echo "fail: unserialize class\n";
    exit(1);
}
if (3 !== $b->getSize() || 10 !== $b[0] || 20 !== $b[1] || 30 !== $b[2]) {
    echo "fail: roundtrip values\n";
    exit(1);
}

$c = new SplFixedArray(3);
$c[0] = 10;
$c[2] = 30;
$d = unserialize(serialize($c));
if (3 !== $d->getSize() || 10 !== $d[0] || null !== $d[1] || 30 !== $d[2]) {
    echo "fail: sparse roundtrip\n";
    exit(1);
}

echo "ok\n";
