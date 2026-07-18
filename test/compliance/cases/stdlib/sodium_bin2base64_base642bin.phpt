--TEST--
stdlib sodium_bin2base64()/sodium_base642bin() + SODIUM_BASE64_VARIANT_* (#20675)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium')
    || !function_exists('sodium_bin2base64')
    || !function_exists('sodium_base642bin')
    || !defined('SODIUM_BASE64_VARIANT_ORIGINAL')) {
    echo "missing\n";
    exit(0);
}
$bin = "hello\0world";
$b64 = sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
echo sodium_base642bin($b64, SODIUM_BASE64_VARIANT_ORIGINAL) === $bin ? "original_ok\n" : "original_fail\n";

$raw = "\xff\xfe\xfd\x00\x01";
echo sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_ORIGINAL) === '//79AAE=' ? "orig_enc_ok\n" : "orig_enc_fail\n";
echo sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING) === '//79AAE' ? "orig_nopad_ok\n" : "orig_nopad_fail\n";
echo sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE) === '__79AAE=' ? "url_enc_ok\n" : "url_enc_fail\n";
echo sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING) === '__79AAE' ? "url_nopad_ok\n" : "url_nopad_fail\n";

$decoded = sodium_base642bin(' //79 AA E= ', SODIUM_BASE64_VARIANT_ORIGINAL, ' ');
echo $decoded === $raw ? "ignore_ok\n" : "ignore_fail\n";

try {
    sodium_bin2base64('x', 99);
    echo "variant_fail\n";
} catch (\SodiumException $e) {
    echo "variant_ok\n";
}
try {
    sodium_base642bin('!!!', SODIUM_BASE64_VARIANT_ORIGINAL);
    echo "bad_fail\n";
} catch (\SodiumException $e) {
    echo "bad_ok\n";
}
try {
    sodium_base642bin('//79AAE', SODIUM_BASE64_VARIANT_ORIGINAL);
    echo "missing_pad_fail\n";
} catch (\SodiumException $e) {
    echo "missing_pad_ok\n";
}
--EXPECT--
original_ok
orig_enc_ok
orig_nopad_ok
url_enc_ok
url_nopad_ok
ignore_ok
variant_ok
bad_ok
missing_pad_ok
