--TEST--
openssl openssl_decrypt(null) no Decryption failed warning on 8.4 (#21465, ext/openssl/openssl.c)
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
$decryptFailedWarnings = 0;
set_error_handler(static function (int $errno, string $message) use (&$decryptFailedWarnings): bool {
    if (E_WARNING === $errno && str_contains($message, 'Decryption failed')) {
        ++$decryptFailedWarnings;
    }

    return true;
});
$r = openssl_decrypt(null, 'AES-128-ECB', $key);
restore_error_handler();
echo 'result='.var_export($r, true)."\n";
echo 'decrypt_failed_warnings='.$decryptFailedWarnings."\n";
?>
--EXPECT--
result=false
decrypt_failed_warnings=0
