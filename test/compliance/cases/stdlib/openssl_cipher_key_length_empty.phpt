--TEST--
stdlib openssl_cipher_key_length() — empty cipher ValueError (#18154, ext/openssl/openssl.c)
--FILE--
<?php
try {
    openssl_cipher_key_length('');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_cipher_key_length(): Argument #1 ($cipher_algo) must not be empty
