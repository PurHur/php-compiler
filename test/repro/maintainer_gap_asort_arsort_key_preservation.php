<?php

declare(strict_types=1);

$a = [3, 1, 2];
asort($a);
if ([1 => 1, 2 => 2, 0 => 3] !== $a) {
    echo 'fail: asort keys/values ', var_export($a, true), "\n";
    exit(1);
}

$b = [3, 1, 2];
arsort($b);
if ([0 => 3, 2 => 2, 1 => 1] !== $b) {
    echo 'fail: arsort keys/values ', var_export($b, true), "\n";
    exit(1);
}

echo "ok\n";
