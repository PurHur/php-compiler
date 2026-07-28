<?php
/**
 * #24110 — AOT-safe probe for SODIUM_* size constants (no generichash call;
 * sodium_crypto_generichash() remains LogicException under JIT/AOT NestedJIT).
 * php-src: ext/sodium/libsodium.stub.php
 */
echo "KEYBYTES_MAX=".SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX."\n";
echo "XCHACHA_NSECBYTES=".SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECBYTES."\n";
echo "SECRETSTREAM_MSGMAX=".SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_MESSAGEBYTES_MAX."\n";
