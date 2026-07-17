--TEST--
openssl openssl_encrypt(null) TypeError on 8.4 forward profile (#20263, re-#19038, ext/openssl/openssl.c)
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
try {
    $r = openssl_encrypt(null, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    echo 'COERCE len='.strlen((string) $r)."\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$r2 = openssl_encrypt('', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo 'empty len='.strlen($r2)."\n";
?>
--EXPECT--
openssl_encrypt(): Argument #1 ($data) must be of type string, null given
empty len=16
