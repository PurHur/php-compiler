<?php
/**
 * #27775 — sodium_crypto_aead_aes256gcm_is_available() Reflection return bool
 * (ext/sodium/libsodium.stub.php).
 */
$fn = 'sodium_crypto_aead_aes256gcm_is_available';
$r = new ReflectionFunction($fn);
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'val=', var_export($fn(), true), "\n";
