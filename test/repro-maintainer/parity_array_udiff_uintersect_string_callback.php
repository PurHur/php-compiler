<?php

declare(strict_types=1);

$udiff = array_udiff([1, 2], [2], 'strcmp');
$uintersect = array_uintersect([1, 2], [2], 'strcmp');

if ([1] !== $udiff) {
    fwrite(STDERR, 'FAIL: array_udiff expected [1], got '.var_export($udiff, true)."\n");
    exit(1);
}
if ([1 => 2] !== $uintersect) {
    fwrite(STDERR, 'FAIL: array_uintersect expected [1=>2], got '.var_export($uintersect, true)."\n");
    exit(1);
}

echo "OK\n";
