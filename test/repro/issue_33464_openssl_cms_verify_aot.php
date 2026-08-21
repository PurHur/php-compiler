<?php

declare(strict_types=1);

// #33464 leftover of #6592 — compile-time path to fixture CMS (test/fixtures/openssl/cms_verify_signed.cms)
// Flags: OPENSSL_CMS_NOVERIFY = 32 (literal so thin AOT sees compileTimeLong).
$signed = 'test/fixtures/openssl/cms_verify_signed.cms';
$out = '/tmp/phpc_cms_verify_33464_out.txt';
@unlink($out);

var_export(openssl_cms_verify($signed, 32, null, [], null, $out));
echo '|';
echo (is_file($out) && file_get_contents($out) === "hello cms\n") ? 'content-ok' : 'content-bad';
echo '|';
var_export(openssl_cms_verify('test/fixtures/openssl/cms_verify_msg.txt', 32));
echo "\n";
