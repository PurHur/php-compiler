--TEST--
stdlib openssl_get_cert_locations() — default CA path metadata (#6560, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!function_exists('openssl_get_cert_locations')) {
    die('skip openssl_get_cert_locations missing');
}
?>
--FILE--
<?php
$locations = openssl_get_cert_locations();
var_export(is_array($locations));
echo "\n";
var_export(array_key_exists('default_cert_file', $locations));
echo "\n";
var_export($locations['default_cert_file_env']);
echo "\n";
var_export($locations['default_cert_dir_env']);
echo "\n";
var_export('' !== $locations['default_cert_file']);
echo "\n";
var_export('' !== $locations['default_cert_dir']);
echo "\n";
var_export(array_key_exists('ini_cafile', $locations));
echo "\n";
var_export(array_key_exists('ini_capath', $locations));
echo "\n";
--EXPECT--
true
true
'SSL_CERT_FILE'
'SSL_CERT_DIR'
true
true
true
true
