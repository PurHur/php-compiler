<?php
declare(strict_types=1);

/**
 * Repro for #20573 — sodium_crypto_sign_ed25519_{sk,pk}_to_curve25519 (php-src surface).
 *
 * Note: sodium_crypto_core_ed25519_* / scalarmult_ed25519* are not in php-src ext/sodium.
 */
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable\n");
    exit(0);
}

$fns = [
    'sodium_crypto_sign_ed25519_sk_to_curve25519',
    'sodium_crypto_sign_ed25519_pk_to_curve25519',
];
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "fail: {$fn}() not registered\n");
        exit(1);
    }
    echo $fn, "=Y\n";
}

$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$pk = sodium_crypto_sign_publickey($kp);
$csk = sodium_crypto_sign_ed25519_sk_to_curve25519($sk);
$cpk = sodium_crypto_sign_ed25519_pk_to_curve25519($pk);
echo 'csk_len=', strlen($csk), "\n";
echo 'cpk_len=', strlen($cpk), "\n";
echo (strlen($csk) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES && strlen($cpk) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)
    ? "lengths_ok\n"
    : "lengths_fail\n";

try {
    sodium_crypto_sign_ed25519_sk_to_curve25519('short');
    echo "sk_len_fail\n";
} catch (SodiumException $e) {
    echo (str_contains($e->getMessage(), 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES') ? "sk_len_ok\n" : "sk_len_msg_fail\n");
}

try {
    sodium_crypto_sign_ed25519_pk_to_curve25519('short');
    echo "pk_len_fail\n";
} catch (SodiumException $e) {
    echo (str_contains($e->getMessage(), 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? "pk_len_ok\n" : "pk_len_msg_fail\n");
}
