<?php

declare(strict_types=1);

// #15151: inline first array + assign-in-call companion — Zend keeps companion order.
array_multisort([3, 1, 2], $labels = ['c', 'a', 'b']);
$expected = ['c', 'a', 'b'];
if ($labels !== $expected) {
    echo 'fail: labels='.json_encode($labels).' expected '.json_encode($expected)."\n";
    exit(1);
}

$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
if ($b !== ['a', 'b', 'c']) {
    echo 'fail: predeclared companion='.json_encode($b)."\n";
    exit(1);
}

echo "ok\n";
