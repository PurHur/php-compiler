<?php

declare(strict_types=1);

// #33474 leftover of #6804 — compile-time paths + cert PEM path (thin AOT bake)
// Headers: null (php-src ?array) — empty [] inline currently lowers to bool on VM
// (same regression hits openssl_cms_sign #33467 repro). Prefer null for bake.
// Do not decrypt in the same AOT unit — ciphertext path only exists after runtime write.
$msg = 'test/fixtures/openssl/pkcs7_verify_msg.txt';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$out = '/tmp/phpc_pkcs7_encrypt_33474.p7m';
@unlink($out);

var_export(openssl_pkcs7_encrypt($msg, $out, $cert, null));
echo '|';
echo (is_file($out) && filesize($out) > 0) ? 'enc-ok' : 'enc-bad';
echo '|';
var_export(@openssl_pkcs7_encrypt('test/fixtures/openssl/missing-pkcs7-msg.txt', $out, $cert, null));
echo "\n";
