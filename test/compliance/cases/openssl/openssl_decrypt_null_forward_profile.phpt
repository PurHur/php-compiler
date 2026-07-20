--TEST--
openssl openssl_decrypt(null) soft-null on 8.4 forward profile (#21445, reverts #20263, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip ffi";
}
if (!function_exists('openssl_decrypt')) {
    echo "skip openssl_decrypt";
}
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$key = str_repeat('k', 16);
$r = openssl_decrypt(null, 'AES-128-ECB', $key);
echo 'result='.var_export($r, true)."\n";
?>
--EXPECT--
result=false
