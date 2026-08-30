<?php

declare(strict_types=1);

/**
 * AOT: openssl_pkey_new($options) with a runtime options array (#35866 leftover of #34015).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new)
 */
$bits = 512;
$opts = [
    'private_key_bits' => $bits,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$k = openssl_pkey_new($opts);
echo is_object($k) ? get_class($k) : var_export($k, true);
echo ' ';
$d = openssl_pkey_get_details($k);
echo is_array($d) ? (string) $d['bits'] : 'no';
echo "\n";
