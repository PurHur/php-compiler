<?php

declare(strict_types=1);

/**
 * #29292 — str_pad() empty $pad_string ValueError must match Zend: "must not be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(str_pad)).
 */
$expected = 'str_pad(): Argument #3 ($pad_string) must not be empty';

try {
    str_pad('a', 5, '');
    fwrite(STDERR, "fail: str_pad(..., '') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
