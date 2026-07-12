<?php
declare(strict_types=1);

if (!enum_exists('ArrayPadType', false)) {
    echo "fail: ArrayPadType enum not registered\n";
    exit(1);
}

$p = array_pad([1], 4, 0, ArrayPadType::Positive);
$n = array_pad([1], 4, 0, ArrayPadType::Negative);
$b = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);

if (4 !== count($p) || 1 !== $p[0] || 0 !== $p[3]) {
    echo "fail positive\n";
    exit(1);
}
if (4 !== count($n) || 0 !== $n[0] || 1 !== $n[3]) {
    echo "fail negative\n";
    exit(1);
}
if (5 !== count($b) || 0 !== $b[0] || 1 !== $b[2] || 0 !== $b[4]) {
    echo "fail both\n";
    exit(1);
}

echo "ok\n";
