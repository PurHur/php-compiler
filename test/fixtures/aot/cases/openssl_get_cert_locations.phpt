--TEST--
AOT: openssl_get_cert_locations() CA path metadata (#32388)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslConfigNative.php';
if (!\PHPCompiler\ext\openssl\VmOpensslConfigNative::available()) {
    echo 'skip';
}
?>
--FILE--
<?php
declare(strict_types=1);

$locations = openssl_get_cert_locations();
echo is_array($locations) ? "array:1\n" : "array:0\n";
echo ($locations['default_cert_file_env'] ?? null) === 'SSL_CERT_FILE' ? "env_file:1\n" : "env_file:0\n";
echo ($locations['default_cert_dir_env'] ?? null) === 'SSL_CERT_DIR' ? "env_dir:1\n" : "env_dir:0\n";
echo ($locations['default_cert_file'] ?? '') !== '' ? "file_ne:1\n" : "file_ne:0\n";
echo ($locations['default_cert_dir'] ?? '') !== '' ? "dir_ne:1\n" : "dir_ne:0\n";
echo array_key_exists('ini_cafile', $locations) ? "ini_cafile:1\n" : "ini_cafile:0\n";
echo array_key_exists('ini_capath', $locations) ? "ini_capath:1\n" : "ini_capath:0\n";
--EXPECT--
array:1
env_file:1
env_dir:1
file_ne:1
dir_ne:1
ini_cafile:1
ini_capath:1
