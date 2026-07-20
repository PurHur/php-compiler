--TEST--
openssl openssl_encrypt(null) soft-null on 8.4 forward profile (#21445, reverts #20263, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip ffi";
}
if (!function_exists('openssl_encrypt')) {
    echo "skip openssl_encrypt";
}
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$iv = str_repeat("\0", 16);
$key = str_repeat('k', 16);
$empty = openssl_encrypt('', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
$null = openssl_encrypt(null, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo 'same='.(($empty === $null) ? '1' : '0')."\n";
echo 'len='.strlen((string) $empty)."\n";
?>
--EXPECT--
same=1
len=16
