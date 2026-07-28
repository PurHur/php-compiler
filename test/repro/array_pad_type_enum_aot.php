<?php
/**
 * #24002 — ArrayPadType / ARRAY_PAD_* are phantoms; assert absence + sign-of-length pad.
 */
declare(strict_types=1);

if (enum_exists('ArrayPadType', false)) {
    echo "fail: ArrayPadType enum must not be registered\n";
    exit(1);
}
if (defined('ARRAY_PAD_BOTH')) {
    echo "fail: ARRAY_PAD_BOTH must not be defined\n";
    exit(1);
}

$p = array_pad([1], 4, 0);
$n = array_pad([1], -4, 0);

if (4 !== count($p) || 1 !== $p[0] || 0 !== $p[3]) {
    echo "fail positive\n";
    exit(1);
}
if (4 !== count($n) || 0 !== $n[0] || 1 !== $n[3]) {
    echo "fail negative\n";
    exit(1);
}

echo "ok\n";
