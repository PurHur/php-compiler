--TEST--
stdlib sodium_crypto_secretstream_xchacha20poly1305_* push/pull (#15462)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretstream_xchacha20poly1305_keygen')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
[$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
$ct = sodium_crypto_secretstream_xchacha20poly1305_push($state, 'hi');
$state2 = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
[$msg, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($state2, $ct);
echo ($msg === 'hi') ? "roundtrip_ok\n" : "roundtrip_fail\n";
echo ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) ? "tag_ok\n" : "tag_fail\n";
--EXPECT--
roundtrip_ok
tag_ok
