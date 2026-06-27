<?php

declare(strict_types=1);

// Zend: ob_implicit_flush(bool) rejects int operands (#12586).
try {
    ob_implicit_flush(0);
    echo "fail: ob_implicit_flush(0) accepted int — Zend requires bool\n";
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type bool')) {
        echo 'fail: unexpected TypeError: '.$e->getMessage()."\n";
        exit(1);
    }
}

ob_implicit_flush(false);
ob_implicit_flush();
echo "ok\n";
