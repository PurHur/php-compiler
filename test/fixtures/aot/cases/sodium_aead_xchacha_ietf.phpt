--TEST--
AOT: sodium_crypto_aead_xchacha20poly1305_ietf encrypt/decrypt roundtrip (#27318)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
// Non-NUL key/nonce — AOT str_repeat("\0", N) has a separate data-pointer defect.
$key = str_repeat('K', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$nonce = str_repeat('N', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('hi', 'ad', $nonce, $key);
$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, 'ad', $nonce, $key);
var_export($pt);
echo "\n";
--EXPECT--
'hi'
