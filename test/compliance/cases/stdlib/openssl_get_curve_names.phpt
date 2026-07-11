--TEST--
stdlib openssl_get_curve_names() — built-in EC curve list (#6560, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!function_exists('openssl_get_curve_names')) {
    die('skip openssl_get_curve_names missing');
}
?>
--FILE--
<?php
$curves = openssl_get_curve_names();
var_export(is_array($curves));
echo "\n";
var_export(count($curves) > 0);
echo "\n";
var_export(in_array('prime256v1', $curves, true));
echo "\n";
var_export(in_array('secp384r1', $curves, true));
echo "\n";
--EXPECT--
true
true
true
true
