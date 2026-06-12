--TEST--
stdlib openssl_cipher_key_length() JIT — compile-time cipher literals (#6522)
--FILE--
<?php
echo openssl_cipher_key_length('aes-256-cbc'), "\n";
echo (openssl_cipher_key_length('not-a-real-cipher-method') === false ? "false\n" : "bad\n");
--EXPECT--
32
false
