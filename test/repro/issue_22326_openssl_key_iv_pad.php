<?php
/**
 * Repro #22326 — openssl_encrypt short/long key + short IV pad/truncate (php-src openssl_backend_common.c).
 */
$iv16 = str_repeat('0', 16);
$key16 = str_repeat('k', 16);
$key8 = str_repeat('k', 8);
$key32 = str_repeat('k', 32);
$iv8 = str_repeat('0', 8);
$zero8 = str_repeat(chr(0), 8);
$key8pad = $key8 . $zero8;
$iv8pad = $iv8 . $zero8;

$full = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv16);
$short = openssl_encrypt('hi', 'aes-128-cbc', $key8, OPENSSL_RAW_DATA, $iv16);
$long = openssl_encrypt('hi', 'aes-128-cbc', $key32, OPENSSL_RAW_DATA, $iv16);
$padKey = openssl_encrypt('hi', 'aes-128-cbc', $key8pad, OPENSSL_RAW_DATA, $iv16);
$shortIv = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv8);
$padIv = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv8pad);

echo 'short_pad=', $short === $padKey ? '1' : '0', PHP_EOL;
echo 'long_trunc=', $long === $full ? '1' : '0', PHP_EOL;
echo 'iv_pad=', $shortIv === $padIv ? '1' : '0', PHP_EOL;
echo 'dont_zero=';
$fail = openssl_encrypt('hi', 'aes-128-cbc', $key8, OPENSSL_DONT_ZERO_PAD_KEY, $iv16);
echo $fail === false ? '1' : '0', PHP_EOL;
