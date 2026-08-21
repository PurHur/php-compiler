<?php

declare(strict_types=1);

// #33466 leftover of #6804 — compile-time path to fixture PKCS#7 (test/fixtures/openssl/pkcs7_verify_signed.p7m)
// Flags: PKCS7_NOVERIFY = 32 (literal so thin AOT sees compileTimeLong).
$signed = 'test/fixtures/openssl/pkcs7_verify_signed.p7m';
$out = '/tmp/phpc_pkcs7_verify_33466_out.txt';
@unlink($out);

var_export(openssl_pkcs7_verify($signed, 32, null, [], null, $out));
echo '|';
echo (is_file($out) && file_get_contents($out) === "hello pkcs7\n") ? 'content-ok' : 'content-bad';
echo '|';
var_export(openssl_pkcs7_verify('test/fixtures/openssl/pkcs7_verify_msg.txt', 32));
echo "\n";
