<?php

declare(strict_types=1);

echo 'openssl_get_cipher_methods: ', function_exists('openssl_get_cipher_methods') ? 'yes' : 'no', "\n";
echo 'openssl_get_md_methods: ', function_exists('openssl_get_md_methods') ? 'yes' : 'no', "\n";
echo 'openssl_cipher_iv_length: ', function_exists('openssl_cipher_iv_length') ? 'yes' : 'no', "\n";
echo 'openssl_digest: ', function_exists('openssl_digest') ? 'yes' : 'no', "\n";

$digest = openssl_digest('data', 'sha256');
echo is_string($digest) ? $digest : 'digest_fail', "\n";

$ciphers = openssl_get_cipher_methods();
echo in_array('aes-256-cbc', $ciphers, true) ? "cipher_ok\n" : "cipher_missing\n";
