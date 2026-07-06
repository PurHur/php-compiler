<?php
declare(strict_types=1);
// Repro #16887 — openssl_cipher_iv_length(cipher_algo:) named parameter (ext/openssl/openssl.stub.php).
echo openssl_cipher_iv_length(cipher_algo: 'AES-128-CBC'), "\n";
echo openssl_cipher_iv_length('AES-128-CBC'), "\n";
