<?php

declare(strict_types=1);

/**
 * Repro #12903 — registry-listed digests must hash (ext/hash/hash.c).
 */

$data = 'test';

$rows = [
    'murmur3a',
    'murmur3c',
    'murmur3f',
    'tiger192,3',
    'whirlpool',
    'gost',
];

foreach ($rows as $algo) {
    try {
        $digest = hash($algo, $data);
        echo $algo.':'.$digest."\n";
    } catch (Throwable $e) {
        echo $algo.':ERR:'.$e->getMessage()."\n";
        exit(1);
    }
}

$hmac = hash_hmac('whirlpool', $data, 'key');
if (128 !== \strlen($hmac)) {
    echo "fail: hash_hmac whirlpool bad length\n";
    exit(1);
}
echo "hmac-ok\n";
echo "ok\n";
