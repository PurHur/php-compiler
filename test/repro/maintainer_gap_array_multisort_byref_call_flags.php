<?php

declare(strict_types=1);

// #15154 — by-ref assign-in-call first operand + trailing SORT_* flags must not TypeError (Zend no-op).
try {
    array_multisort($a = [3, 1, 2], SORT_ASC, SORT_NUMERIC);
} catch (Throwable $e) {
    echo 'fail: threw ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

if ([3, 1, 2] !== $a) {
    echo 'fail: expected [3,1,2] unsorted, got ', json_encode($a), "\n";
    exit(1);
}

$b = [3, 1, 2];
array_multisort($b, SORT_ASC, SORT_NUMERIC);
if ([1, 2, 3] !== $b) {
    echo 'fail: separate-variable form expected [1,2,3], got ', json_encode($b), "\n";
    exit(1);
}

echo "ok\n";
