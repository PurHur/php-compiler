<?php

declare(strict_types=1);

// Inline public-key string in the array so compileTimeArray is populated (#32979).
$sealed = '';
$ekeys = [];
$iv = '';
$len = openssl_seal(
    'hello-seal',
    $sealed,
    $ekeys,
    [
        "-----BEGIN PUBLIC KEY-----\nMFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB\nlQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==\n-----END PUBLIC KEY-----",
    ],
    'AES-128-CBC',
    $iv
);
var_export(false !== $len && $len > 0);
echo '|';
// Avoid strlen($sealed): standalone AOT can keep the '' compile-time literal (#15642).
echo md5($sealed) === 'd41d8cd98f00b204e9800998ecf8427e' ? 'sealed-bad' : 'sealed-ok';
echo '|';
echo 1 === \count($ekeys) && md5($ekeys[0]) !== 'd41d8cd98f00b204e9800998ecf8427e' ? 'ekeys-ok' : 'ekeys-bad';
echo '|';
echo md5($iv) === 'd41d8cd98f00b204e9800998ecf8427e' ? 'iv-bad' : 'iv-ok';
echo '|';
$badSealed = '';
$badEkeys = [];
$badIv = '';
var_export(false === @openssl_seal('hello-seal', $badSealed, $badEkeys, ['not-a-key'], 'AES-128-CBC', $badIv));
echo "\n";
