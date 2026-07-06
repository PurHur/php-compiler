<?php

declare(strict_types=1);

// Maintainer repro: round(-0.5) half-away-from-zero sign (#16903, ext/standard/math.c).

$negHalf = round(-0.5);
if (-1.0 !== $negHalf) {
    fwrite(STDERR, 'fail: round(-0.5) expected -1, got '.var_export($negHalf, true)."\n");
    exit(1);
}

$negOneHalf = round(-1.5);
if (-2.0 !== $negOneHalf) {
    fwrite(STDERR, 'fail: round(-1.5) expected -2, got '.var_export($negOneHalf, true)."\n");
    exit(1);
}

$posHalf = round(0.5);
if (1.0 !== $posHalf) {
    fwrite(STDERR, 'fail: round(0.5) expected 1, got '.var_export($posHalf, true)."\n");
    exit(1);
}

echo "ok\n";
