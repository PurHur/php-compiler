--TEST--
AOT: openssl_cipher_key_length() — AES-256-CBC key length (#6522)
--FILE--
<?php
echo function_exists('openssl_cipher_key_length') ? "exists\n" : "missing\n";
var_dump(openssl_cipher_key_length('aes-256-cbc'));
var_dump(openssl_cipher_key_length('not-a-real-cipher-method'));
--EXPECT--
exists
int(32)
bool(false)
