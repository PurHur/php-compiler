--TEST--
AOT: openssl_cipher_iv_length() cipher_algo: named parameter (#16887)
--FILE--
<?php
declare(strict_types=1);
echo openssl_cipher_iv_length(cipher_algo: 'AES-128-CBC'), "\n";
echo openssl_cipher_iv_length(cipher_algo: 'aes-256-cbc'), "\n";
--EXPECT--
16
16
