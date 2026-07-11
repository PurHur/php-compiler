--TEST--
stdlib sodium_crypto_stream()/stream_xor()/stream_keygen() ChaCha20 roundtrip (#15464)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_stream')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_stream_keygen();
$nonce = random_bytes(SODIUM_CRYPTO_STREAM_NONCEBYTES);
$plain = 'probe';
$enc = sodium_crypto_stream_xor($plain, $nonce, $key);
$dec = sodium_crypto_stream_xor($enc, $nonce, $key);
echo ($dec === $plain) ? "xor_ok\n" : "xor_fail\n";
echo (32 === strlen(sodium_crypto_stream(32, $nonce, $key))) ? "stream_len_ok\n" : "stream_len_fail\n";
--EXPECT--
xor_ok
stream_len_ok
