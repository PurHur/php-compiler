<?php

declare(strict_types=1);

$reduced = array_reduce([1, 2, 3], 'intval');

if (0 !== $reduced) {
    fwrite(STDERR, 'FAIL: array_reduce(intval) expected 0, got '.var_export($reduced, true)."\n");
    exit(1);
}

echo "OK\n";
