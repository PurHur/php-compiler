<?php

declare(strict_types=1);

// Maintainer gap #16864 — str_decrement('a') must throw ValueError (php-src ext/standard/string.c).
try {
    str_decrement('a');
    fwrite(STDERR, "FAIL: expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
