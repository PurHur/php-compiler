<?php

declare(strict_types=1);

try {
    php_strip_whitespace(null);
    echo "fail: php_strip_whitespace(null) expected TypeError under strict_types\n";
    exit(1);
} catch (TypeError $e) {
    if ('php_strip_whitespace(): Argument #1 ($filename) must be of type string, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
