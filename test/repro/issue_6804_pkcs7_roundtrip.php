<?php

$cert = file_get_contents(__DIR__.'/../fixtures/openssl/pkcs7_test_cert.pem');
$key = file_get_contents(__DIR__.'/../fixtures/openssl/pkcs7_test_key.pem');
$tmpdir = sys_get_temp_dir().'/pkcs7_rt_'.getmypid();
@mkdir($tmpdir);
$msg = $tmpdir.'/msg.txt';
$signed = $tmpdir.'/signed.p7m';
$verified = $tmpdir.'/verified.txt';
$enc = $tmpdir.'/enc.p7m';
$dec = $tmpdir.'/dec.txt';
file_put_contents($msg, "hello pkcs7\n");

echo 'sign_exists=', function_exists('openssl_pkcs7_sign') ? 'y' : 'n', "\n";
echo 'PKCS7_DETACHED=', defined('PKCS7_DETACHED') ? (string) PKCS7_DETACHED : 'undef', "\n";
echo 'PKCS7_NOVERIFY=', defined('PKCS7_NOVERIFY') ? (string) PKCS7_NOVERIFY : 'undef', "\n";

$flagsNoVerify = PKCS7_NOVERIFY;
$flagsBinary = PKCS7_BINARY;
$cipher = OPENSSL_CIPHER_AES_128_CBC;
echo 'flagsNoVerify=', var_export($flagsNoVerify, true), "\n";

$ok = openssl_pkcs7_sign($msg, $signed, $cert, $key, [], $flagsBinary);
echo 'sign=', var_export($ok, true), "\n";
$ok = openssl_pkcs7_verify($signed, $flagsNoVerify, null, [], null, $verified);
echo 'verify=', var_export($ok, true), "\n";
echo 'content=', var_export(@file_get_contents($verified), true), "\n";

$ok = openssl_pkcs7_encrypt($msg, $enc, $cert, [], $flagsBinary, $cipher);
echo 'encrypt=', var_export($ok, true), "\n";
$ok = openssl_pkcs7_decrypt($enc, $dec, $cert, $key);
echo 'decrypt=', var_export($ok, true), "\n";
echo 'plain=', var_export(@file_get_contents($dec), true), "\n";
