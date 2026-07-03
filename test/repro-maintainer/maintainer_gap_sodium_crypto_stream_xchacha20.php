<?php
declare(strict_types=1);
// Maintainer gap repro: sodium XChaCha20 stream API (#15461).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_stream_xchacha20',
    'sodium_crypto_stream_xchacha20_xor',
    'sodium_crypto_stream_xchacha20_xor_ic',
    'sodium_crypto_stream_xchacha20_keygen',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "MISSING {$fn}\n");
        exit(1);
    }
}

$key = sodium_crypto_stream_xchacha20_keygen();
$nonce = random_bytes(SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES);
$plain = 'hello xchacha20 stream';
$enc = sodium_crypto_stream_xchacha20_xor($plain, $nonce, $key);
$dec = sodium_crypto_stream_xchacha20_xor($enc, $nonce, $key);
if ($dec !== $plain) {
    fwrite(STDERR, "xor round-trip failed\n");
    exit(1);
}

$icEnc = sodium_crypto_stream_xchacha20_xor_ic($plain, $nonce, 0, $key);
$icDec = sodium_crypto_stream_xchacha20_xor_ic($icEnc, $nonce, 0, $key);
if ($icDec !== $plain) {
    fwrite(STDERR, "xor_ic round-trip failed\n");
    exit(1);
}

$stream = sodium_crypto_stream_xchacha20(16, $nonce, $key);
if (16 !== strlen($stream)) {
    fwrite(STDERR, "stream length mismatch\n");
    exit(1);
}

echo "xchacha20_stream_ok\n";
