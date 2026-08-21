<?php

declare(strict_types=1);

// #33472 leftover of #6592 — compile-time paths + recipient cert path (thin AOT bake)
// Flags: OPENSSL_CMS_BINARY = 128
// null headers (not []) — empty-array INIT_ARRAY currently aliases to bool on this VM (#33467 repro).
$msg = 'test/fixtures/openssl/cms_verify_msg.txt';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$out = '/tmp/phpc_cms_encrypt_33472.cms';
@unlink($out);

var_export(openssl_cms_encrypt($msg, $out, $cert, null, 128));
echo '|';
echo (is_file($out) && filesize($out) > 0) ? 'enc-ok' : 'enc-bad';
echo '|';
var_export(@openssl_cms_encrypt('test/fixtures/openssl/missing-cms-msg.txt', $out, $cert, null, 128));
echo "\n";
