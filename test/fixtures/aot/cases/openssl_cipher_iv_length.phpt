--TEST--
AOT: openssl_cipher_iv_length() — AES-256-CBC IV length (#7331)
--FILE--
<?php
echo function_exists('openssl_cipher_iv_length') ? "exists\n" : "missing\n";
echo openssl_cipher_iv_length('aes-256-cbc'), "\n";
echo (openssl_cipher_iv_length('not-a-real-cipher-method') === false ? "false\n" : "bad\n");
--EXPECT--
exists
16
false
