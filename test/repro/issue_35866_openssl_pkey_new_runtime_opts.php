<?php
// AOT: openssl_pkey_new($opts) with a runtime options array (#35866 leftover of #34015).
$bits = 512;
$opts = [
    'private_key_bits' => $bits,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$k = openssl_pkey_new($opts);
echo is_object($k) ? get_class($k) : var_export($k, true);
echo ' ';
$details = is_object($k) ? openssl_pkey_get_details($k) : false;
echo (\is_array($details) && isset($details['bits'])) ? (string) $details['bits'] : 'no-bits';
echo "\n";
