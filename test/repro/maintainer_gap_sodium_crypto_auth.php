<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_crypto_auth')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_auth unavailable\n");
    exit(0);
}

$key = random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES);
$msg = 'hello';
$mac = sodium_crypto_auth($msg, $key);
$ok = sodium_crypto_auth_verify($mac, $msg, $key);
$bad = sodium_crypto_auth_verify($mac, 'wrong', $key);

echo $ok ? "verify_ok\n" : "verify_fail\n";
echo $bad ? "bad_ok\n" : "bad_fail\n";
echo strlen($mac) === SODIUM_CRYPTO_AUTH_BYTES ? "mac_len_ok\n" : "mac_len_fail\n";
