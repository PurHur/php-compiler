<?php

declare(strict_types=1);

// #33482 leftover of #6804 — compile-time paths (fixture ciphertext from pkcs7_encrypt)
$enc = 'test/fixtures/openssl/pkcs7_decrypt_cipher.p7m';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$key = 'test/fixtures/openssl/pkcs7_test_key.pem';
$out = '/tmp/phpc_pkcs7_decrypt_33482.txt';
@unlink($out);

var_export(openssl_pkcs7_decrypt($enc, $out, $cert, $key));
echo '|';
echo (is_file($out) && file_get_contents($out) === "hello pkcs7\n") ? 'plain-ok' : 'plain-bad';
echo '|';
var_export(@openssl_pkcs7_decrypt('test/fixtures/openssl/missing-pkcs7.p7m', $out, $cert, $key));
echo "\n";
