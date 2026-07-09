<?php

declare(strict_types=1);

// Maintainer repro: #10280 — array_diff_uassoc uses user callback on keys, internal compare on values.
$a = ['a' => 1];
$b = ['A' => 1];
$cmp = static fn ($k1, $k2) => strcasecmp((string) $k1, (string) $k2);
$diff = array_diff_uassoc($a, $b, $cmp);
$intersect = array_intersect_uassoc($a, $b, $cmp);
if ([] !== $diff || [ 'a' => 1 ] !== $intersect) {
    echo 'bad diff=', var_export($diff, true), ' intersect=', var_export($intersect, true), "\n";
    exit(1);
}

$cmp2 = static fn ($v1, $v2) => $v1 <=> $v2;
$d2 = array_diff_uassoc([0 => 1], [0 => true], $cmp2);
if ([] !== $d2) {
    echo "bad value-key mixed ", var_export($d2, true), "\n";
    exit(1);
}
$i2 = array_intersect_uassoc([0 => 1], [0 => true], $cmp2);
if ([0 => 1] !== $i2) {
    echo "bad intersect value-key mixed ", var_export($i2, true), "\n";
    exit(1);
}

echo "ok\n";
