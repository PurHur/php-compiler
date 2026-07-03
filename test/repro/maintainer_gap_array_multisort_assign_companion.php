<?php

declare(strict_types=1);

$a = [3, 1, 2];
array_multisort($a, $labels = ['c', 'a', 'b']);

if ([1, 2, 3] !== $a) {
    echo 'fail: a=', json_encode($a), "\n";
    exit(1);
}
if (['c', 'a', 'b'] !== $labels) {
    echo 'fail: labels=', json_encode($labels), "\n";
    exit(1);
}

echo "ok\n";
