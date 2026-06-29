<?php

declare(strict_types=1);

$slice = array_slice([1, 2, 3, 4], array_search(3, [1, 2, 3, 4]));
if ([3, 4] !== $slice) {
    fwrite(STDERR, 'expected [3,4], got '.var_export($slice, true)."\n");
    exit(1);
}

$off = array_search(3, [1, 2, 3, 4]);
$viaVar = array_slice([1, 2, 3, 4], $off);
if ([3, 4] !== $viaVar) {
    fwrite(STDERR, 'variable offset form failed: '.var_export($viaVar, true)."\n");
    exit(1);
}

echo "ok\n";
