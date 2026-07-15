--TEST--
openssl openssl_encrypt(null) — TypeError under strict_types (#19038, ext/openssl/openssl.c)
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

try {
    openssl_encrypt(null, 'aes-256-cbc', $key, 0, $iv);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
openssl_encrypt(): Argument #1 ($data) must be of type string, null given
