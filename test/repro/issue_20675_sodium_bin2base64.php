<?php
/**
 * Repro for #20675 — sodium_bin2base64 / sodium_base642bin + SODIUM_BASE64_VARIANT_*.
 */
echo function_exists('sodium_bin2base64') ? "fn_bin2base64_ok\n" : "fn_bin2base64_missing\n";
echo function_exists('sodium_base642bin') ? "fn_base642bin_ok\n" : "fn_base642bin_missing\n";
echo defined('SODIUM_BASE64_VARIANT_ORIGINAL') ? "const_original_ok\n" : "const_original_missing\n";
echo defined('SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING') ? "const_urlsafe_nopad_ok\n" : "const_urlsafe_nopad_missing\n";

$bin = "hello\0world";
$b64 = sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
echo sodium_base642bin($b64, SODIUM_BASE64_VARIANT_ORIGINAL) === $bin ? "roundtrip_original_ok\n" : "roundtrip_original_fail\n";

$url = sodium_bin2base64("\xff\xfe\xfd\x00\x01", SODIUM_BASE64_VARIANT_URLSAFE);
echo $url === '__79AAE=' ? "urlsafe_encode_ok\n" : "urlsafe_encode_fail:$url\n";

try {
    sodium_bin2base64('x', 99);
    echo "variant_fail\n";
} catch (\SodiumException $e) {
    echo false !== strpos($e->getMessage(), 'valid base64 variant') ? "variant_err_ok\n" : "variant_err_bad\n";
}

try {
    sodium_base642bin('!!!', SODIUM_BASE64_VARIANT_ORIGINAL);
    echo "bad_b64_fail\n";
} catch (\SodiumException $e) {
    echo false !== strpos($e->getMessage(), 'valid base64 string') ? "bad_b64_err_ok\n" : "bad_b64_err_bad\n";
}
