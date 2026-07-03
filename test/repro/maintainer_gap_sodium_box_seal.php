<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_crypto_box_seal')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_box_seal unavailable\n");
    exit(0);
}

$kp = sodium_crypto_box_keypair();
$pk = sodium_crypto_box_publickey($kp);
$ct = sodium_crypto_box_seal('secret', $pk);
$pt = sodium_crypto_box_seal_open($ct, $kp);

echo function_exists('sodium_crypto_box_keypair') ? "keypair_exists\n" : "keypair_missing\n";
echo function_exists('sodium_crypto_box_seal') ? "seal_exists\n" : "seal_missing\n";
echo function_exists('sodium_crypto_box_seal_open') ? "open_exists\n" : "open_missing\n";
echo $pt === 'secret' ? "roundtrip_ok\n" : "roundtrip_fail\n";
echo \strlen($pk) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ? "pk_len_ok\n" : "pk_len_fail\n";
