<?php

declare(strict_types=1);

// #34030 leftover of #33496 — AOT openssl_pkey_get_details happy path
$k = openssl_pkey_new(['private_key_bits' => 512]);
if (!is_object($k)) {
    echo "no-key\n";
    exit(1);
}
$d = openssl_pkey_get_details($k);
if (!is_array($d)) {
    echo "fail\n";
    exit(1);
}
echo isset($d['bits']) ? (string) $d['bits'] : '?';
echo '|';
echo isset($d['type']) ? (string) $d['type'] : '?';
echo '|';
echo (isset($d['key']) && is_string($d['key']) && str_contains($d['key'], 'BEGIN PUBLIC KEY'))
    ? 'pub-ok'
    : 'pub-bad';
echo "\n";
