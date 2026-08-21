<?php

declare(strict_types=1);

// #33464 leftover of #6592 — compile-time path/flag literals for openssl_cms_verify().
// Fixture must exist at compile time (PHPUnit writes /tmp/phpc_cms_verify_33464/signed.cms).
$signed = '/tmp/phpc_cms_verify_33464/signed.cms';
$content = '/tmp/phpc_cms_verify_33464/content.txt';
$missing = '/tmp/phpc_cms_verify_33464/missing.cms';
$flags = 32; // OPENSSL_CMS_NOVERIFY
@unlink($content);

var_export(openssl_cms_verify($signed, $flags));
echo '|';
var_export(openssl_cms_verify($signed, $flags, null, [], null, $content));
echo '|';
$out = @file_get_contents($content);
echo (\is_string($out) && $out === "hello cms\n") ? 'content-ok' : 'content-bad';
echo '|';
var_export(openssl_cms_verify($missing, $flags));
echo "\n";
