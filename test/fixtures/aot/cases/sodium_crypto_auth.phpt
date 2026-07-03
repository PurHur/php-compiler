--TEST--
AOT: sodium_crypto_auth()/sodium_crypto_auth_verify() MAC roundtrip (#15514)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_auth')) {
    echo "missing\n";
    exit(0);
}
$key = random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES);
$msg = 'aot';
$mac = sodium_crypto_auth($msg, $key);
echo sodium_crypto_auth_verify($mac, $msg, $key) ? "ok\n" : "fail\n";
--EXPECT--
ok
