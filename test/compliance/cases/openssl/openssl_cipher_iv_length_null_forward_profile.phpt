--TEST--
openssl openssl_cipher_iv_length(null) — TypeError on 8.4 forward profile (#19491, ext/openssl/openssl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    openssl_cipher_iv_length(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    openssl_cipher_iv_length('');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must be of type string, null given
openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must not be empty
