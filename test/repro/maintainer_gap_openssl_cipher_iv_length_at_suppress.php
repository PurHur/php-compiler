<?php

declare(strict_types=1);

@openssl_cipher_iv_length('nope');
$ivLast = error_get_last();
@openssl_cipher_key_length('nope');
$keyLast = error_get_last();
@openssl_digest('data', 'nope');
$digestLast = error_get_last();

$ok = str_contains($ivLast['message'] ?? '', 'Unknown cipher algorithm')
    && str_contains($keyLast['message'] ?? '', 'Unknown cipher algorithm')
    && str_contains($digestLast['message'] ?? '', 'Unknown digest algorithm')
    && false === @openssl_cipher_iv_length('nope');

echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
