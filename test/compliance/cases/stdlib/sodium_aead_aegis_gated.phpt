--TEST--
stdlib sodium AEGIS AEAD — withheld without libsodium AEGIS (#20518)
--FILE--
<?php
declare(strict_types=1);

// Reference profile (Ubuntu 22.04 libsodium 1.0.18 / Zend without AEGIS): no phantom symbols.
$funcs = [
    'sodium_crypto_aead_aegis128l_encrypt',
    'sodium_crypto_aead_aegis128l_decrypt',
    'sodium_crypto_aead_aegis128l_keygen',
    'sodium_crypto_aead_aegis256_encrypt',
    'sodium_crypto_aead_aegis256_decrypt',
    'sodium_crypto_aead_aegis256_keygen',
];
$phantom = false;
foreach ($funcs as $f) {
    if (function_exists($f)) {
        $phantom = true;
        break;
    }
}
$consts = [
    'SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS128L_NPUBBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS128L_NSECBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS128L_ABYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS256_NSECBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS256_ABYTES',
];
foreach ($consts as $c) {
    if (defined($c)) {
        $phantom = true;
        break;
    }
}
echo $phantom ? "fail\n" : "ok\n";
echo function_exists('sodium_crypto_aead_aes256gcm_encrypt') ? "aesgcm_yes\n" : "aesgcm_no\n";
--EXPECT--
ok
aesgcm_yes
