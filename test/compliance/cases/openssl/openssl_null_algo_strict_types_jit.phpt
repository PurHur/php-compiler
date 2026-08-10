--TEST--
openssl_* null cipher/digest algo TypeError under strict_types — JIT (#29956)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip ffi";
}
if (!function_exists('openssl_encrypt') || !function_exists('openssl_digest')) {
    echo "skip openssl";
}
--JIT--
--FILE--
<?php
declare(strict_types=1);

$key = str_repeat('k', 16);
$iv = str_repeat('i', 16);

try {
    openssl_encrypt('plain', null, $key, 0, $iv);
    echo "encrypt uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    openssl_decrypt('a', null, $key);
    echo "decrypt uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    openssl_digest('x', null);
    echo "digest uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
openssl_encrypt(): Argument #2 ($cipher_algo) must be of type string, null given
openssl_decrypt(): Argument #2 ($cipher_algo) must be of type string, null given
openssl_digest(): Argument #2 ($digest_algo) must be of type string, null given
