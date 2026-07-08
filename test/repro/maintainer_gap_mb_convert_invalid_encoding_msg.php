<?php

declare(strict_types=1);

$expected = 'mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "INVALID"';
try {
    mb_convert_encoding('x', 'UTF-8', 'INVALID');
    fwrite(STDERR, "fail: no exception\n");
    exit(1);
} catch (ValueError $e) {
    if ($e->getMessage() !== $expected) {
        fwrite(STDERR, "fail: message mismatch\n");
        fwrite(STDERR, "expected: {$expected}\n");
        fwrite(STDERR, "actual:   {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
