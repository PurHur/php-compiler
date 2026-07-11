--TEST--
stdlib openssl_cipher_iv_length() — AES-256-CBC IV length (#7331, ext/openssl/openssl.c)
--FILE--
<?php
echo function_exists('openssl_cipher_iv_length') ? "exists\n" : "missing\n";
var_dump(openssl_cipher_iv_length('aes-256-cbc'));
var_dump(openssl_cipher_iv_length('not-a-real-cipher-method'));
--EXPECTF--
PHP Warning:  openssl_cipher_iv_length(): Unknown cipher algorithm in %s on line %d
exists
int(16)
bool(false)
