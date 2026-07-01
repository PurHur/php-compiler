<?php

declare(strict_types=1);

// Issue #10059 — array_multisort()/array_splice() array: named parameter (php-src basic_functions.stub.php)

try {
    $a = [3, 1, 2];
    array_multisort(array: $a);
    echo 'multisort ', implode(',', $a), "\n";
} catch (Throwable $e) {
    echo 'multisort fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

$b = [1, 2, 3];
try {
    $removed = array_splice(array: $b, offset: 0, length: 1);
    echo 'splice removed=', $removed[0], ' rest=', implode(',', $b), "\n";
} catch (Throwable $e) {
    echo 'splice fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
