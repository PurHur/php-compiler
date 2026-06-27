<?php

declare(strict_types=1);

try {
    array_multisort($a = [3, 1, 2]);
} catch (Throwable $e) {
    echo 'fail: array_multisort($a=[3,1,2]) threw ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

if ([3, 1, 2] !== $a) {
    echo 'fail: expected [3,1,2] after assign-in-arg, got ', json_encode($a), "\n";
    exit(1);
}

$b = [2, 1];
array_multisort($b = [2, 1]);
if ([2, 1] !== $b) {
    echo 'fail: expected [2,1] after assign-in-arg, got ', json_encode($b), "\n";
    exit(1);
}

echo "ok\n";
