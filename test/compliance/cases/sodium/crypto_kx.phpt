--TEST--
sodium_crypto_kx_* key exchange (#20047)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
$need = [
    'sodium_crypto_kx_keypair',
    'sodium_crypto_kx_publickey',
    'sodium_crypto_kx_secretkey',
    'sodium_crypto_kx_seed_keypair',
    'sodium_crypto_kx_client_session_keys',
    'sodium_crypto_kx_server_session_keys',
];
foreach ($need as $fn) {
    if (!function_exists($fn)) {
        echo "missing\n";
        exit(0);
    }
}
echo "all_exist\n";
echo (SODIUM_CRYPTO_KX_SESSIONKEYBYTES === 32) ? "const_ok\n" : "const_fail\n";

$alice = sodium_crypto_kx_keypair();
$bob = sodium_crypto_kx_keypair();
[$rx, $tx] = sodium_crypto_kx_client_session_keys($alice, sodium_crypto_kx_publickey($bob));
[$rx2, $tx2] = sodium_crypto_kx_server_session_keys($bob, sodium_crypto_kx_publickey($alice));
echo ($rx === $tx2 && $tx === $rx2) ? "session_ok\n" : "session_fail\n";
echo (SODIUM_CRYPTO_KX_SESSIONKEYBYTES === strlen($rx)) ? "key_len_ok\n" : "key_len_fail\n";

$seed = str_repeat("\x01", SODIUM_CRYPTO_KX_SEEDBYTES);
$a = sodium_crypto_kx_seed_keypair($seed);
$b = sodium_crypto_kx_seed_keypair($seed);
echo ($a === $b) ? "seed_det\n" : "seed_nondet\n";

$bad = false;
try {
    sodium_crypto_kx_seed_keypair('x');
} catch (SodiumException $e) {
    $bad = str_contains($e->getMessage(), 'SODIUM_CRYPTO_KX_SEEDBYTES');
}
echo $bad ? "seed_err\n" : "seed_ok\n";
--EXPECT--
all_exist
const_ok
session_ok
key_len_ok
seed_det
seed_err
