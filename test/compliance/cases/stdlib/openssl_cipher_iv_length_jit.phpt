--TEST--
stdlib openssl_cipher_iv_length() JIT — compile-time cipher literals (#7331)
--FILE--
<?php
echo openssl_cipher_iv_length('aes-256-cbc'), "\n";
echo (openssl_cipher_iv_length('not-a-real-cipher-method') === false ? "false\n" : "bad\n");
--EXPECTF--
PHP Warning:  openssl_cipher_iv_length(): Unknown cipher algorithm in %s on line %d
16
false
