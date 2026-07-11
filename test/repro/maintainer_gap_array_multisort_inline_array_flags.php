<?php

declare(strict_types=1);

try {
    array_multisort([1, 2], SORT_DESC, SORT_NUMERIC);
    echo "ok\n";
} catch (Throwable $e) {
    echo 'fail: ', $e->getMessage(), "\n";
    exit(1);
}
