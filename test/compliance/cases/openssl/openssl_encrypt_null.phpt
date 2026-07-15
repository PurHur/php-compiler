--TEST--
openssl openssl_encrypt(null) — coerces to empty string ciphertext (#19016, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip ffi";
}
if (!function_exists('openssl_encrypt')) {
    echo "skip openssl_encrypt";
}
--FILE--
<?php
declare(strict_types=1);

$key = str_repeat('k', 32);
$iv = str_repeat('i', 16);

$empty = @openssl_encrypt('', 'aes-256-cbc', $key, 0, $iv);
$null = @openssl_encrypt(null, 'aes-256-cbc', $key, 0, $iv);

if (false === $empty || false === $null) {
    echo "fail\n";
    exit(1);
}

echo ($empty === $null) ? "match\n" : "mismatch\n";
echo strlen($null) > 0 ? "nonempty\n" : "empty\n";
--EXPECT--
match
nonempty
