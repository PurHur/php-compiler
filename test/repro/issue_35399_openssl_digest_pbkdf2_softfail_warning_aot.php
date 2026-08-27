<?php

declare(strict_types=1);

// AOT bake softfail must emit Zend-shaped E_WARNING for unknown digest algos (#35399).
// php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_digest) / openssl_pbkdf2
// No set_error_handler(closure) — AOT requires compile-time string callback (#1379).

var_export(openssl_digest('hello', 'not-a-digest'));
echo "\n";
var_export(openssl_pbkdf2('p', 's', 16, 1000, 'not-a-digest'));
echo "\n";
