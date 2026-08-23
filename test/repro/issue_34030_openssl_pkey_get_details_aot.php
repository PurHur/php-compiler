<?php
/**
 * Repro #34030 — openssl_pkey_get_details(openssl_pkey_new()) AOT happy path.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_details)
 */
$k = openssl_pkey_new(['private_key_bits' => 512]);
$d = openssl_pkey_get_details($k);
if (!is_array($d)) {
    echo 'fail', PHP_EOL;
    exit(1);
}
echo (string) $d['bits'], PHP_EOL;
echo (string) $d['type'], PHP_EOL;
echo isset($d['key']) && is_string($d['key']) && str_contains($d['key'], 'BEGIN PUBLIC KEY')
    ? 'has-key'
    : 'no-key', PHP_EOL;
