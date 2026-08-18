<?php
/**
 * openssl_pbkdf2() JIT/AOT leftover of #6488 (#32410; php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pbkdf2)).
 * RFC 6070 SHA-256: password="password", salt="salt", c=1 / 1000, dkLen=20.
 * Compare via hex2bin() of literals — AOT bin2hex() of raw bytes SIGSEGVs on this tree.
 * $pw/$len variables prove runtime lowering (compile-time bake #32429 throws LogicException).
 */
$d1 = openssl_pbkdf2('password', 'salt', 20, 1, 'sha256');
echo ($d1 === hex2bin('120fb6cffcf8b32c43e7225256c4f837a86548c9')) ? "v1ok\n" : "v1bad\n";
$d2 = openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256');
echo ($d2 === hex2bin('632c2812e46d4604102ba7618e9d6d7d2f8128f6')) ? "v2ok\n" : "v2bad\n";
$pw = 'password';
$salt = 'salt';
$len = 20;
$iter = 1000;
$algo = 'sha256';
$d3 = openssl_pbkdf2($pw, $salt, $len, $iter, $algo);
echo ($d3 === hex2bin('632c2812e46d4604102ba7618e9d6d7d2f8128f6')) ? "v3ok\n" : "v3bad\n";
var_dump(openssl_pbkdf2('password', 'salt', 20, 0, 'sha256'));
var_dump(@openssl_pbkdf2('password', 'salt', 20, 1000, 'nope'));
try {
    openssl_pbkdf2('password', 'salt', 0, 1000);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
