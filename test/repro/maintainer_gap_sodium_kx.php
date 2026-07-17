<?php
declare(strict_types=1);

/**
 * Repro for #20047 — sodium_crypto_kx_* key exchange (php-src ext/sodium/libsodium.c).
 */
$fns = [
    'sodium_crypto_kx_keypair',
    'sodium_crypto_kx_publickey',
    'sodium_crypto_kx_secretkey',
    'sodium_crypto_kx_seed_keypair',
    'sodium_crypto_kx_client_session_keys',
    'sodium_crypto_kx_server_session_keys',
];
$missing = 0;
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        echo $fn, "_missing\n";
        ++$missing;
    }
}
if ($missing > 0) {
    echo "missing_count=", $missing, "\n";
    exit(0);
}
echo "all_exist\n";

echo 'PUBLICKEYBYTES=', SODIUM_CRYPTO_KX_PUBLICKEYBYTES, "\n";
echo 'SECRETKEYBYTES=', SODIUM_CRYPTO_KX_SECRETKEYBYTES, "\n";
echo 'KEYPAIRBYTES=', SODIUM_CRYPTO_KX_KEYPAIRBYTES, "\n";
echo 'SEEDBYTES=', SODIUM_CRYPTO_KX_SEEDBYTES, "\n";
echo 'SESSIONKEYBYTES=', SODIUM_CRYPTO_KX_SESSIONKEYBYTES, "\n";

$alice = sodium_crypto_kx_keypair();
$bob = sodium_crypto_kx_keypair();
echo 'kp_len=', strlen($alice), "\n";
echo 'pk_len=', strlen(sodium_crypto_kx_publickey($alice)), "\n";
echo 'sk_len=', strlen(sodium_crypto_kx_secretkey($alice)), "\n";

$seed = str_repeat('A', SODIUM_CRYPTO_KX_SEEDBYTES);
$seeded = sodium_crypto_kx_seed_keypair($seed);
$seeded2 = sodium_crypto_kx_seed_keypair($seed);
echo 'seed_det=', ($seeded === $seeded2) ? '1' : '0', "\n";
echo 'seed_pk_ok=', (
    sodium_crypto_kx_publickey($seeded) === substr($seeded, SODIUM_CRYPTO_KX_SECRETKEYBYTES)
) ? '1' : '0', "\n";

[$rx, $tx] = sodium_crypto_kx_client_session_keys($alice, sodium_crypto_kx_publickey($bob));
[$rx2, $tx2] = sodium_crypto_kx_server_session_keys($bob, sodium_crypto_kx_publickey($alice));
echo 'session_ok=', ($rx === $tx2 && $tx === $rx2) ? '1' : '0', "\n";
echo 'key_len=', strlen($rx), "\n";

$bad = false;
try {
    sodium_crypto_kx_publickey('short');
} catch (SodiumException $e) {
    $bad = str_contains($e->getMessage(), 'SODIUM_CRYPTO_KX_KEYPAIRBYTES');
}
echo $bad ? "len_err_ok\n" : "len_err_fail\n";
