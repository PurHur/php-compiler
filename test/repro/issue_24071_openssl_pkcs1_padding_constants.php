<?php
declare(strict_types=1);

foreach (['OPENSSL_PKCS1_PADDING', 'OPENSSL_NO_PADDING', 'OPENSSL_PKCS1_OAEP_PADDING', 'OPENSSL_RAW_DATA'] as $c) {
    echo $c, "\t", defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}

if (function_exists('openssl_public_encrypt') && function_exists('openssl_pkey_new')
    && defined('OPENSSL_PKCS1_PADDING') && defined('OPENSSL_KEYTYPE_RSA')) {
    $key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (false !== $key) {
        $pem = '';
        openssl_pkey_export($key, $pem);
        $encrypted = '';
        $ok = openssl_public_encrypt('pad-const', $encrypted, $pem, OPENSSL_PKCS1_PADDING);
        echo 'named-encrypt=', $ok ? '1' : '0', "\n";
    }
}
