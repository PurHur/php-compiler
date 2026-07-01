<?php

try {
    php_strip_whitespace(null);
    echo "fail: php_strip_whitespace(null) expected ValueError\n";
    exit(1);
} catch (ValueError $e) {
    if ('Path cannot be empty' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
