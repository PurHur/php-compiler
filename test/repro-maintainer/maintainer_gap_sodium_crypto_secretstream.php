<?php
declare(strict_types=1);
// Maintainer gap repro: sodium secretstream API (#15462).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_secretstream_xchacha20poly1305_keygen',
    'sodium_crypto_secretstream_xchacha20poly1305_init_push',
    'sodium_crypto_secretstream_xchacha20poly1305_push',
    'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
    'sodium_crypto_secretstream_xchacha20poly1305_pull',
    'sodium_crypto_secretstream_xchacha20poly1305_rekey',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "MISSING {$fn}\n");
        exit(1);
    }
}

$key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
[$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
$ct1 = sodium_crypto_secretstream_xchacha20poly1305_push($state, 'hello');
$ct2 = sodium_crypto_secretstream_xchacha20poly1305_push(
    $state,
    ' world',
    '',
    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
);
$state2 = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
[$msg1, $tag1] = sodium_crypto_secretstream_xchacha20poly1305_pull($state2, $ct1);
[$msg2, $tag2] = sodium_crypto_secretstream_xchacha20poly1305_pull($state2, $ct2);
if ($msg1.$msg2 !== 'hello world') {
    fwrite(STDERR, "round-trip message mismatch\n");
    exit(1);
}
if ($tag2 !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
    fwrite(STDERR, "final tag mismatch\n");
    exit(1);
}

$state3 = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
$bad = str_repeat("\0", strlen($ct1));
if (false !== sodium_crypto_secretstream_xchacha20poly1305_pull($state3, $bad)) {
    fwrite(STDERR, "tampered pull should fail\n");
    exit(1);
}

echo "ok\n";
