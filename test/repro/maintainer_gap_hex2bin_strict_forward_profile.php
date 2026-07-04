<?php

declare(strict_types=1);

try {
    hex2bin('zz', strict: true);
    fwrite(STDERR, "FAIL: expected Error on invalid hex\n");
    exit(1);
} catch (\ArgumentCountError $e) {
    fwrite(STDERR, "FAIL: strict arity withheld on forward profile: {$e->getMessage()}\n");
    exit(1);
} catch (\Error $e) {
    echo "ok:", $e->getMessage(), "\n";
}
