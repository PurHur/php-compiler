<?php
declare(strict_types=1);
// Maintainer gap repro: sodium ChaCha20 stream API (#15464).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_stream',
    'sodium_crypto_stream_xor',
    'sodium_crypto_stream_keygen',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "MISSING {$fn}\n");
        exit(1);
    }
}

$key = sodium_crypto_stream_keygen();
$nonce = random_bytes(SODIUM_CRYPTO_STREAM_NONCEBYTES);
$plain = 'hello stream cipher';
$enc = sodium_crypto_stream_xor($plain, $nonce, $key);
$dec = sodium_crypto_stream_xor($enc, $nonce, $key);
if ($dec !== $plain) {
    fwrite(STDERR, "xor round-trip failed\n");
    exit(1);
}

$stream = sodium_crypto_stream(32, $nonce, $key);
if (32 !== strlen($stream)) {
    fwrite(STDERR, "stream length mismatch\n");
    exit(1);
}

echo "stream_ok\n";
