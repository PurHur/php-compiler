<?php

declare(strict_types=1);

// Issue #18525 — number_format() with decimals > 14 must not zero fractional digits.
$out = number_format(1.1, 20);
$expected = '1.10000000000000008882';
if ($out !== $expected) {
    fwrite(STDERR, "number_format(1.1, 20): expected {$expected}, got {$out}\n");
    exit(1);
}

$neg = number_format(1.5, -1);
if ('2' !== $neg) {
    fwrite(STDERR, "number_format(1.5, -1): expected 2, got {$neg}\n");
    exit(1);
}

echo "ok\n";
