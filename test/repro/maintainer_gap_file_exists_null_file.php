<?php

declare(strict_types=1);

try {
    file_exists(null);
    echo "fail: file_exists(null) accepted null — Zend requires TypeError\n";
    exit(1);
} catch (TypeError $e) {
    $expected = 'file_exists(): Argument #1 ($filename) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        echo 'fail: file_exists(null) got ', $e->getMessage(), "\n";
        exit(1);
    }
}
echo "ok\n";
