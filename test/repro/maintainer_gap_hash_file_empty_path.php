<?php

declare(strict_types=1);

$checks = [
    'md5_file' => static fn () => md5_file(''),
    'sha1_file' => static fn () => sha1_file(''),
    'hash_file' => static fn () => hash_file('md5', ''),
    'hash_hmac_file' => static fn () => hash_hmac_file('md5', '', 'key'),
];

foreach ($checks as $fn => $call) {
    try {
        $call();
        echo "fail: {$fn}(\"\") expected ValueError\n";
        exit(1);
    } catch (ValueError $e) {
        if ('Path must not be empty' !== $e->getMessage()) {
            echo "fail: {$fn}(): {$e->getMessage()}\n";
            exit(1);
        }
    }
}
echo "ok\n";
