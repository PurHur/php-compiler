<?php

declare(strict_types=1);

$rii = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, 2]));
if (!$rii->valid()) {
    fwrite(STDERR, "RecursiveIteratorIterator::valid() false at start\n");
    exit(1);
}

$empty = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
if ($empty->valid()) {
    fwrite(STDERR, "RecursiveIteratorIterator::valid() true on empty inner\n");
    exit(1);
}

$out = [];
foreach ($rii as $value) {
    $out[] = $value;
}
if ($out !== [1, 2]) {
    fwrite(STDERR, 'foreach='.json_encode($out)." expected [1,2]\n");
    exit(1);
}

echo "ok\n";
