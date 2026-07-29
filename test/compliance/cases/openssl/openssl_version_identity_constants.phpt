--TEST--
openssl OPENSSL_VERSION_TEXT / OPENSSL_VERSION_NUMBER / OPENSSL_DEFAULT_STREAM_CIPHERS (#24070)
--SKIPIF--
<?php
if (!extension_loaded('openssl')) die('skip openssl not loaded');
if (!PHPCompiler\ext\openssl\VmOpensslConfigNative::available()
    && !defined('OPENSSL_VERSION_TEXT')) {
    die('skip no libcrypto FFI and no host OPENSSL_VERSION_*');
}
?>
--FILE--
<?php
declare(strict_types=1);

// Bare names (not constant()) so AOT matches VM.
$text = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null;
$number = defined('OPENSSL_VERSION_NUMBER') ? OPENSSL_VERSION_NUMBER : null;
$ciphers = defined('OPENSSL_DEFAULT_STREAM_CIPHERS') ? OPENSSL_DEFAULT_STREAM_CIPHERS : null;

echo 'OPENSSL_VERSION_TEXT=',
    (is_string($text) && str_starts_with($text, 'OpenSSL ')) ? 'ok' : 'UNDEF',
    "\n";
echo 'OPENSSL_VERSION_NUMBER=',
    (is_int($number) && $number > 0x10000000) ? 'ok' : 'UNDEF',
    "\n";
echo 'OPENSSL_DEFAULT_STREAM_CIPHERS=',
    (is_string($ciphers) && str_contains($ciphers, 'AES128-GCM-SHA256') && str_contains($ciphers, '!ADH')) ? 'ok' : 'UNDEF',
    "\n";
echo 'OPENSSL_RAW_DATA=', defined('OPENSSL_RAW_DATA') && OPENSSL_RAW_DATA === 1 ? 'ok' : 'bad', "\n";
?>
--EXPECT--
OPENSSL_VERSION_TEXT=ok
OPENSSL_VERSION_NUMBER=ok
OPENSSL_DEFAULT_STREAM_CIPHERS=ok
OPENSSL_RAW_DATA=ok
