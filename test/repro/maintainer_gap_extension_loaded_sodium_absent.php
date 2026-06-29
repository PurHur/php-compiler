<?php

declare(strict_types=1);

if (!extension_loaded('sodium')) {
    echo "skip: host ext/sodium not loaded\n";
    exit(0);
}

$loaded = extension_loaded('sodium');
$exists = function_exists('sodium_crypto_secretbox');
if (!$loaded || !$exists) {
    echo 'fail: extension_loaded=', var_export($loaded, true), ' function_exists=', var_export($exists, true), "\n";
    exit(1);
}

$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox('probe', $nonce, $key);
$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
if ('probe' !== $plain) {
    echo 'fail: roundtrip=', var_export($plain, true), "\n";
    exit(1);
}

echo "ok\n";
