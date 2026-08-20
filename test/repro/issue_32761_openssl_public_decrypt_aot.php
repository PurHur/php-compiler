<?php

declare(strict_types=1);

// Same keypair as #32705 / #32713 / #32757. Ciphertext is openssl_private_encrypt('hello')
// with that private key (PKCS#1 type-1 — deterministic).
$pub = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB
lQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==
-----END PUBLIC KEY-----
PEM;

$cipher = "\x19\xff\x1e\xf3\xe8\x24\x65\x19\x8c\x28\x41\x5d\x9b\xe9\xac\x12\x4c\x70\xa9\x8f\xb7\x56\x4a\x06\xdf\x7f\x9f\x39\x68\x92\x7e\x16\x44\x44\x77\xbd\x7f\x14\xc8\xe2\x9e\x6a\xc8\x9d\x12\x82\xdc\x9b\x8a\x0c\x77\x32\xab\x44\xd4\x7a\x5f\xb8\xb3\x76\x6e\x91\x3f\x72";

$out = '';
var_export(openssl_public_decrypt($cipher, $out, $pub));
echo '|';
// Avoid strlen($out): standalone AOT can keep the '' compile-time literal (#15642).
echo $out === 'hello' ? 'plain-ok' : 'plain-bad';
echo '|';
$bad = '';
var_export(openssl_public_decrypt($cipher, $bad, 'not-a-key'));
echo "\n";
