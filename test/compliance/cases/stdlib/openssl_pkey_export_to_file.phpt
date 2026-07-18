--TEST--
stdlib openssl_pkey_export_to_file() — PEM to path (#20287, ext/openssl/openssl.c)
--SKIPIF--
<?php if (!PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) die('skip no libcrypto FFI'); ?>
--FILE--
<?php
declare(strict_types=1);
var_export(function_exists('openssl_pkey_export_to_file'));
echo "\n";

$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}

$pem = '';
if (!openssl_pkey_export($key, $pem) || $pem === '') {
    echo "export-fail\n";
    exit(1);
}

$dir = sys_get_temp_dir().'/phpc-pkey-export-'.getmypid();
mkdir($dir);
$file = $dir.'/key.pem';
var_export(openssl_pkey_export_to_file($key, $file));
echo "\n";
$fromFile = file_get_contents($file);
echo ($fromFile === $pem) ? "file-ok\n" : "file-bad\n";

$encFile = $dir.'/key-enc.pem';
var_export(openssl_pkey_export_to_file($key, $encFile, 'secret'));
echo "\n";
$enc = file_get_contents($encFile);
echo (str_contains($enc, 'ENCRYPTED') || str_contains($enc, 'BEGIN ENCRYPTED')) ? "enc-ok\n" : "enc-bad\n";
$loaded = openssl_pkey_get_private($enc, 'secret');
echo (false !== $loaded) ? "load-enc-ok\n" : "load-enc-fail\n";

$bad = $dir.'/missing-subdir/key.pem';
var_export(openssl_pkey_export_to_file($key, $bad));
echo "\n";

unlink($file);
unlink($encFile);
rmdir($dir);
--EXPECT--
true
true
file-ok
true
enc-ok
load-enc-ok
false
