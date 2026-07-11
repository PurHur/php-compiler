<?php

declare(strict_types=1);

try {
    hex2bin('zz', strict: true);
    echo "FAIL: expected Error on invalid hex\n";
    exit(1);
} catch (\ArgumentCountError $e) {
    echo "FAIL: strict arity withheld on forward profile: {$e->getMessage()}\n";
    exit(1);
} catch (\Error $e) {
    echo "ok:{$e->getMessage()}\n";
}
