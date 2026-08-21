<?php

declare(strict_types=1);

// #33471 leftover of #6804 — compile-time paths + PEM paths (thin AOT bake)
// Flags: PKCS7_DETACHED = 64
// Note: do not call openssl_pkcs7_verify() on $out in the same AOT unit — verify also bakes at
// compile time and the signed path only exists after the runtime file_put_contents.
$msg = 'test/fixtures/openssl/pkcs7_verify_msg.txt';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$key = 'test/fixtures/openssl/pkcs7_test_key.pem';
$out = '/tmp/phpc_pkcs7_sign_33471.p7m';
@unlink($out);

var_export(openssl_pkcs7_sign($msg, $out, $cert, $key, null, 64));
echo '|';
echo (is_file($out) && filesize($out) > 0) ? 'signed-ok' : 'signed-bad';
echo '|';
var_export(@openssl_pkcs7_sign('test/fixtures/openssl/missing-pkcs7-msg.txt', $out, $cert, $key, null, 64));
echo "\n";
