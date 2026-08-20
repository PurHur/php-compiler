<?php

declare(strict_types=1);

$pub = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB
lQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==
-----END PUBLIC KEY-----
PEM;

$out = '';
var_export(openssl_public_encrypt('hello', $out, $pub));
echo '|';
// Avoid strlen($out): standalone AOT can keep the '' compile-time literal (#15642).
echo md5($out) === 'd41d8cd98f00b204e9800998ecf8427e' ? 'cipher-bad' : 'cipher-ok';
echo '|';
$bad = '';
var_export(openssl_public_encrypt('hello', $bad, 'not-a-key'));
echo "\n";
