<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_crypto_box_keypair')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_box_keypair unavailable\n");
    exit(0);
}

echo function_exists('sodium_crypto_box') ? "box_exists\n" : "box_missing\n";
echo function_exists('sodium_crypto_box_open') ? "open_exists\n" : "open_missing\n";
echo function_exists('sodium_crypto_box_keypair_from_secretkey_and_publickey') ? "from_exists\n" : "from_missing\n";
echo function_exists('sodium_crypto_box_publickey_from_secretkey') ? "pk_from_exists\n" : "pk_from_missing\n";

$alice = sodium_crypto_box_keypair();
$bob = sodium_crypto_box_keypair();
$alice_sk = sodium_crypto_box_secretkey($alice);
$alice_pk = sodium_crypto_box_publickey($alice);
$bob_sk = sodium_crypto_box_secretkey($bob);
$bob_pk = sodium_crypto_box_publickey($bob);

$alice_to_bob = sodium_crypto_box_keypair_from_secretkey_and_publickey($alice_sk, $bob_pk);
$bob_to_alice = sodium_crypto_box_keypair_from_secretkey_and_publickey($bob_sk, $alice_pk);
$pk_from = sodium_crypto_box_publickey_from_secretkey($alice_sk);
echo $pk_from === $alice_pk ? "pk_from_ok\n" : "pk_from_fail\n";

$nonce = str_repeat("\0", SODIUM_CRYPTO_BOX_NONCEBYTES);
$ct = sodium_crypto_box('hi', $nonce, $alice_to_bob);
$pt = sodium_crypto_box_open($ct, $nonce, $bob_to_alice);
echo $pt === 'hi' ? "roundtrip_ok\n" : "roundtrip_fail\n";

$bad = sodium_crypto_box_open($ct, $nonce, $alice);
echo false === $bad ? "tamper_false\n" : "tamper_fail\n";

try {
    sodium_crypto_box('hi', 'short', $alice_to_bob);
    echo "nonce_ok_unexpected\n";
} catch (Throwable $e) {
    echo strpos($e->getMessage(), 'SODIUM_CRYPTO_BOX_NONCEBYTES') !== false ? "nonce_err_ok\n" : "nonce_err_fail\n";
}
