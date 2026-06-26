<?php

declare(strict_types=1);

try {
    array_multisort([1, 2], [2, 1]);
    echo "ok\n";
} catch (Throwable $e) {
    echo 'fail: ', $e->getMessage(), "\n";
    exit(1);
}
