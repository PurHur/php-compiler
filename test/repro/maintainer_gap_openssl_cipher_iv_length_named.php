<?php

declare(strict_types=1);

$iv = openssl_cipher_iv_length(cipher_algo: 'AES-128-CBC');
$key = openssl_cipher_key_length(cipher_algo: 'AES-128-CBC');
$ok = 16 === $iv && 16 === $key;

echo $ok ? "ok\n" : "fail iv=$iv key=$key\n";
exit($ok ? 0 : 1);
