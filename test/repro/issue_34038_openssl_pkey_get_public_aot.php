<?php

declare(strict_types=1);

// #34038 leftover of #33499 — AOT openssl_pkey_get_public from details['key'] PEM
$k = openssl_pkey_new(['private_key_bits' => 512]);
if (!is_object($k)) {
    echo "no-key\n";
    exit(1);
}
$d = openssl_pkey_get_details($k);
if (!is_array($d) || !isset($d['key']) || !is_string($d['key'])) {
    echo "no-details\n";
    exit(1);
}
$pub = openssl_pkey_get_public($d['key']);
echo is_object($pub) ? 'ok' : 'fail';
echo '|';
$alias = openssl_get_publickey($d['key']);
echo is_object($alias) ? 'alias-ok' : 'alias-fail';
echo "\n";
