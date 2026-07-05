--TEST--
stdlib openssl_cipher_key_length() — AES-256-CBC key length (#6522, ext/openssl/openssl.c)
--FILE--
<?php
echo function_exists('openssl_cipher_key_length') ? "exists\n" : "missing\n";
var_dump(openssl_cipher_key_length('aes-256-cbc'));
var_dump(openssl_cipher_key_length('not-a-real-cipher-method'));
--EXPECTF--
PHP Warning:  openssl_cipher_key_length(): Unknown cipher algorithm in %s on line %d
exists
int(32)
bool(false)
