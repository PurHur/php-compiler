--TEST--
stdlib sodium_crypto_stream_xchacha20* XChaCha20 stream/XOR roundtrip (#15461)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_stream_xchacha20_xor')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_stream_xchacha20_keygen();
$nonce = random_bytes(SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES);
$plain = 'xchacha probe';
$enc = sodium_crypto_stream_xchacha20_xor($plain, $nonce, $key);
$dec = sodium_crypto_stream_xchacha20_xor($enc, $nonce, $key);
echo ($dec === $plain) ? "xor_ok\n" : "xor_fail\n";
$icDec = sodium_crypto_stream_xchacha20_xor_ic(
    sodium_crypto_stream_xchacha20_xor_ic($plain, $nonce, 0, $key),
    $nonce,
    0,
    $key
);
echo ($icDec === $plain) ? "xor_ic_ok\n" : "xor_ic_fail\n";
--EXPECT--
xor_ok
xor_ic_ok
