<?php

declare(strict_types=1);

$expected = [1, 2];
$inline = array_map(intval(...), str_split(str_repeat('12', 1)));
if ($inline !== $expected) {
    echo 'fail inline fcc';
    exit(1);
}

$h = str_split('12');
if (array_map(intval(...), $h) !== $expected) {
    echo 'fail variable fcc';
    exit(1);
}

$inlineFn = array_map(static fn (string $x): int => (int) $x, str_split(str_repeat('12', 1)));
if ($inlineFn !== $expected) {
    echo 'fail inline closure';
    exit(1);
}

echo "ok\n";
