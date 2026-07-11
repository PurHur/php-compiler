<?php

declare(strict_types=1);

/**
 * Repro #12903 — SHA-3 family listed in hash_algos() must digest (ext/hash/hash_sha.c).
 */

$data = 'test';

$d224 = hash('sha3-224', $data);
$d256 = hash('sha3-256', $data);
$d384 = hash('sha3-384', $data);
$d512 = hash('sha3-512', $data);

echo 'sha3-224:'.$d224."\n";
echo 'sha3-256:'.$d256."\n";
echo 'sha3-384:'.$d384."\n";
echo 'sha3-512:'.$d512."\n";

$hmac = hash_hmac('sha3-256', $data, 'key');
if (64 !== \strlen($hmac)) {
    echo "fail: hash_hmac bad length\n";
    exit(1);
}
echo "hmac-ok\n";
echo "ok\n";
