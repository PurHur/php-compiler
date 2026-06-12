--TEST--
AOT: openssl_cipher_key_length() — AES-256-CBC key length (#6522)
--FILE--
<?php
echo function_exists('openssl_cipher_key_length') ? "exists\n" : "missing\n";
echo openssl_cipher_key_length('aes-256-cbc'), "\n";
echo (openssl_cipher_key_length('not-a-real-cipher-method') === false ? "false\n" : "bad\n");
--EXPECT--
exists
32
false
