--TEST--
openssl openssl_decrypt(null) TypeError on 8.4 forward profile (#20263, re-#19038, ext/openssl/openssl.c)
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
$iv = str_repeat("\0", 16);
$key = str_repeat('k', 16);
try {
    $r = openssl_decrypt(null, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    echo 'COERCE '.var_export($r, true)."\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
openssl_decrypt(): Argument #1 ($data) must be of type string, null given
