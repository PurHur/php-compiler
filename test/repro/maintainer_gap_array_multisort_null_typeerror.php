<?php

declare(strict_types=1);

try {
    array_multisort(null);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_multisort(): Argument #1 ($array) must be an array or a sort flag' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
