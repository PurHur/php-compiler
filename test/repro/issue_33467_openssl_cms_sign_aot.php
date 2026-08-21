<?php

declare(strict_types=1);

// #33467 leftover of #6592 — compile-time paths + PEM paths (thin AOT bake)
// Flags: OPENSSL_CMS_BINARY = 128
// Note: do not call openssl_cms_verify() on $out in the same AOT unit — verify also bakes at
// compile time and the signed path only exists after the runtime file_put_contents.
$msg = 'test/fixtures/openssl/cms_verify_msg.txt';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$key = 'test/fixtures/openssl/pkcs7_test_key.pem';
$out = '/tmp/phpc_cms_sign_33467.cms';
@unlink($out);

var_export(openssl_cms_sign($msg, $out, $cert, $key, [], 128));
echo '|';
echo (is_file($out) && filesize($out) > 0) ? 'signed-ok' : 'signed-bad';
echo '|';
var_export(@openssl_cms_sign('test/fixtures/openssl/missing-cms-msg.txt', $out, $cert, $key, [], 128));
echo "\n";
