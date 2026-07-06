--TEST--
stdlib openssl_cipher_iv_length() cipher_algo: named param JIT (#16887, ext/openssl/openssl.stub.php)
--FILE--
<?php
echo openssl_cipher_iv_length(cipher_algo: 'AES-128-CBC'), "\n";
--EXPECT--
16
