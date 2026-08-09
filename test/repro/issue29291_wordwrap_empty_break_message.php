<?php

declare(strict_types=1);

/**
 * #29291 — wordwrap() empty $break ValueError must match Zend: "must not be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(wordwrap)).
 */
$expected = 'wordwrap(): Argument #3 ($break) must not be empty';

try {
    wordwrap('abcd', 2, '', true);
    fwrite(STDERR, "fail: wordwrap(..., '') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
