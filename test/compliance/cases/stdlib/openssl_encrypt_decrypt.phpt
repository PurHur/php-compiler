--TEST--
stdlib openssl_encrypt()/openssl_decrypt() AES-128-CBC round-trip (#18594, ext/openssl/openssl.c)
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

$key = '0123456789abcdef';
$iv = '0123456789abcdef';

$raw = openssl_encrypt('data', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo bin2hex($raw), "\n";
echo openssl_decrypt($raw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv), "\n";

$b64 = openssl_encrypt('data', 'AES-128-CBC', $key, 0, $iv);
echo $b64, "\n";
echo openssl_decrypt($b64, 'AES-128-CBC', $key, 0, $iv), "\n";
--EXPECT--
840a0c413dca6e7dcc58783214795053
data
hAoMQT3Kbn3MWHgyFHlQUw==
data
