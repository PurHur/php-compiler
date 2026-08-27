<?php

declare(strict_types=1);

// AOT bake softfail must emit Zend-shaped E_WARNING for invalid keys (#35382).
// php-src: ext/openssl/openssl.c — php_openssl_pkey_from_zval
// No set_error_handler(closure) — AOT requires compile-time string callback (#1379).

$out = '';
var_export(openssl_public_encrypt('hello', $out, 'not-a-key'));
echo "\n";
var_export(openssl_private_encrypt('hello', $out, 'not-a-key'));
echo "\n";
var_export(openssl_private_decrypt('hello', $out, 'not-a-key'));
echo "\n";
var_export(openssl_public_decrypt('hello', $out, 'not-a-key'));
echo "\n";
