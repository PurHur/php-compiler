<?php
/**
 * #35866 leftover of #34015 — openssl_pkey_new($opts) with a runtime options array.
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 */
$bits = 512;
$opts = [
    'private_key_bits' => $bits,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$k = openssl_pkey_new($opts);
echo is_object($k) ? get_class($k) : var_export($k, true);
echo ' ';
$details = openssl_pkey_get_details($k);
echo is_array($details) ? (string) $details['bits'] : 'no_details';
echo "\n";
