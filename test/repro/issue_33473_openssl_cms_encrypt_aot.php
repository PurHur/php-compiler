<?php

declare(strict_types=1);

// #33473 leftover of #6592 — compile-time paths + cert path (thin AOT bake)
// Flags: OPENSSL_CMS_BINARY = 128
// Note: do not call openssl_cms_decrypt() on $out in the same AOT unit — decrypt is still
// JIT-unimplemented and the ciphertext path only exists after runtime file_put_contents.
$msg = 'test/fixtures/openssl/cms_verify_msg.txt';
$cert = 'test/fixtures/openssl/pkcs7_test_cert.pem';
$out = '/tmp/phpc_cms_encrypt_33473.cms';
@unlink($out);

var_export(openssl_cms_encrypt($msg, $out, $cert, null, 128));
echo '|';
echo (is_file($out) && filesize($out) > 0) ? 'enc-ok' : 'enc-bad';
echo '|';
var_export(@openssl_cms_encrypt('test/fixtures/openssl/missing-cms-msg.txt', $out, $cert, null, 128));
echo "\n";
