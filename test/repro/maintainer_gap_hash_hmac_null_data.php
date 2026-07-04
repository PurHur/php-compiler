<?php

declare(strict_types=1);

try {
    hash_hmac('md5', null, 'key');
    fwrite(STDERR, "fail: hash_hmac accepted null data under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    $expected = 'hash_hmac(): Argument #2 ($data) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'fail: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "ok\n";
