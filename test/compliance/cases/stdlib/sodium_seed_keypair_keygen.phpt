--TEST--
stdlib sodium seed_keypair + AEAD keygen (#21019)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_box_keypair')) {
    echo "missing\n";
    exit(0);
}
foreach ([
    'sodium_crypto_box_seed_keypair',
    'sodium_crypto_sign_seed_keypair',
    'sodium_crypto_sign_keypair_from_secretkey_and_publickey',
    'sodium_crypto_aead_xchacha20poly1305_ietf_keygen',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$seed = str_repeat("\0", SODIUM_CRYPTO_BOX_SEEDBYTES);
$kp = sodium_crypto_box_seed_keypair($seed);
echo 'box_kp_len=', strlen($kp), "\n";
$kp2 = sodium_crypto_box_seed_keypair($seed);
echo 'box_seed_det=', ($kp === $kp2) ? '1' : '0', "\n";
$signSeed = str_repeat("\1", SODIUM_CRYPTO_SIGN_SEEDBYTES);
$skp = sodium_crypto_sign_seed_keypair($signSeed);
echo 'sign_kp_len=', strlen($skp), "\n";
$ssk = sodium_crypto_sign_secretkey($skp);
$spk = sodium_crypto_sign_publickey($skp);
$from = sodium_crypto_sign_keypair_from_secretkey_and_publickey($ssk, $spk);
echo 'sign_from_eq=', ($from === $skp) ? '1' : '0', "\n";
$xk = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
echo 'xchacha_key_len=', strlen($xk), "\n";
if (function_exists('sodium_crypto_aead_aes256gcm_keygen')) {
    echo 'aes_keygen=', function_exists('sodium_crypto_aead_aes256gcm_keygen') ? '1' : '0', "\n";
    $ak = sodium_crypto_aead_aes256gcm_keygen();
    echo 'aes_key_len=', strlen($ak), "\n";
} else {
    echo "aes_keygen=0\n";
    echo "aes_key_len=skip\n";
}
--EXPECT--
sodium_crypto_box_seed_keypair=1
sodium_crypto_sign_seed_keypair=1
sodium_crypto_sign_keypair_from_secretkey_and_publickey=1
sodium_crypto_aead_xchacha20poly1305_ietf_keygen=1
box_kp_len=64
box_seed_det=1
sign_kp_len=96
sign_from_eq=1
xchacha_key_len=32
aes_keygen=1
aes_key_len=32
