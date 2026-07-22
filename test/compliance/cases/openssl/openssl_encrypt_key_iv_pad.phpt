--TEST--
openssl_encrypt()/decrypt() short/long key + IV pad/truncate (#22326, openssl_backend_common.c)
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
error_reporting(E_ALL & ~E_WARNING);
$iv16 = str_repeat('0', 16);
$key16 = str_repeat('k', 16);
$key8 = str_repeat('k', 8);
$key32 = str_repeat('k', 32);
$iv8 = str_repeat('0', 8);
$zero8 = str_repeat(chr(0), 8);
$key8pad = $key8 . $zero8;
$iv8pad = $iv8 . $zero8;

$full = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv16);
$short = openssl_encrypt('hi', 'aes-128-cbc', $key8, OPENSSL_RAW_DATA, $iv16);
$long = openssl_encrypt('hi', 'aes-128-cbc', $key32, OPENSSL_RAW_DATA, $iv16);
$padKey = openssl_encrypt('hi', 'aes-128-cbc', $key8pad, OPENSSL_RAW_DATA, $iv16);
$shortIv = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv8);
$padIv = openssl_encrypt('hi', 'aes-128-cbc', $key16, OPENSSL_RAW_DATA, $iv8pad);

echo 'short_pad=', $short === $padKey ? '1' : '0', "\n";
echo 'long_trunc=', $long === $full ? '1' : '0', "\n";
echo 'iv_pad=', $shortIv === $padIv ? '1' : '0', "\n";

$ct = openssl_encrypt('hi', 'aes-128-cbc', $key8, 0, $iv16);
$pt = openssl_decrypt($ct, 'aes-128-cbc', $key8, 0, $iv16);
echo 'roundtrip=', $pt === 'hi' ? '1' : '0', "\n";

$fail = openssl_encrypt('hi', 'aes-128-cbc', $key8, OPENSSL_DONT_ZERO_PAD_KEY, $iv16);
echo 'dont_zero=', $fail === false ? '1' : '0', "\n";
echo 'const=', defined('OPENSSL_DONT_ZERO_PAD_KEY') && OPENSSL_DONT_ZERO_PAD_KEY === 4 ? '1' : '0', "\n";
?>
--EXPECT--
short_pad=1
long_trunc=1
iv_pad=1
roundtrip=1
dont_zero=1
const=1
