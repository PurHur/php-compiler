<?php

declare(strict_types=1);

/**
 * #30487 — md5_file/sha1_file/hash_hmac_file empty path → Path cannot be empty.
 */
$expected = 'Path cannot be empty';
$checks = [
    'md5_file' => static fn () => md5_file(''),
    'sha1_file' => static fn () => sha1_file(''),
    'hash_hmac_file' => static fn () => hash_hmac_file('sha256', '', 'key'),
];

foreach ($checks as $fn => $call) {
    try {
        $call();
        fwrite(STDERR, "fail: {$fn}(\"\") expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: {$fn}: got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':', $e->getMessage(), "\n";
    }
}

echo "ok\n";
