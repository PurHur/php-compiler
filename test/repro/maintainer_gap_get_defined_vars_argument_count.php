<?php

declare(strict_types=1);

try {
    get_defined_vars(null);
    echo "fail: get_defined_vars(null) expected ArgumentCountError\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if ('get_defined_vars() expects exactly 0 arguments, 1 given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
