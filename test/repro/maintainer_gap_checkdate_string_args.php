<?php

declare(strict_types=1);

try {
    checkdate('2', 29, 2020);
    fwrite(STDERR, "expected TypeError for string month\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'checkdate(): Argument #1 ($month) must be of type int, string given')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
}

if (!checkdate(2, 29, 2020)) {
    fwrite(STDERR, "checkdate(2, 29, 2020) should be true\n");
    exit(1);
}

if (checkdate(2, 30, 2020)) {
    fwrite(STDERR, "checkdate(2, 30, 2020) should be false\n");
    exit(1);
}

echo "ok\n";
