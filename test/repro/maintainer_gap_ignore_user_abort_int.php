<?php

declare(strict_types=1);

// Zend: ignore_user_abort(?bool) rejects int operands (#12585).
try {
    ignore_user_abort(0);
    echo "fail: ignore_user_abort(0) accepted int — Zend requires ?bool\n";
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type bool')) {
        echo 'fail: unexpected TypeError: '.$e->getMessage()."\n";
        exit(1);
    }
}

if (0 !== ignore_user_abort(false)) {
    echo "fail: ignore_user_abort(false) return\n";
    exit(1);
}

if (0 !== ignore_user_abort(null)) {
    echo "fail: ignore_user_abort(null) return\n";
    exit(1);
}

echo "ok\n";
