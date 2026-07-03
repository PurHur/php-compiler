<?php

declare(strict_types=1);

// #15151: inline first array + assign-in-call companion — Zend keeps companion order.
array_multisort([3, 1, 2], $labels = ['c', 'a', 'b']);

if (['c', 'a', 'b'] !== $labels) {
    echo 'fail: labels=', json_encode($labels), "\n";
    exit(1);
}

echo "ok\n";
