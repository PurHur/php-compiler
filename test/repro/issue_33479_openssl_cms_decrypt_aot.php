<?php

declare(strict_types=1);

// #33479 leftover of #6592 — compile-time paths (fixture ciphertext from OPENSSL_CMS_BINARY encrypt)
$enc = 'test/fixtures/openssl/cms_decrypt_cipher.cms';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$key = 'test/fixtures/openssl/pkcs7_test_key.pem';
$out = '/tmp/phpc_cms_decrypt_33479.txt';
@unlink($out);

var_export(openssl_cms_decrypt($enc, $out, $cert, $key));
echo '|';
echo (is_file($out) && file_get_contents($out) === "hello cms\n") ? 'plain-ok' : 'plain-bad';
echo '|';
var_export(@openssl_cms_decrypt('test/fixtures/openssl/missing-cms.cms', $out, $cert, $key));
echo "\n";
