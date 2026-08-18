<?php
/**
 * #32410 — openssl_pbkdf2() AOT matches Zend/VM (php-src ext/openssl/openssl.c).
 *
 * bin2hex() AOT SIGSEGVs on this tree even for 'hello'; compare raw key bytes instead.
 */
$expect = "\x63\x2c\x28\x12\xe4\x6d\x46\x04\x10\x2b\xa7\x61\x8e\x9d\x6d\x7d\x2f\x81\x28\xf6";
echo openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256') === $expect ? "632c2812e46d4604102ba7618e9d6d7d2f8128f6\n" : "mismatch\n";
var_dump(openssl_pbkdf2('password', 'salt', 20, 0, 'sha256'));
var_dump(@openssl_pbkdf2('password', 'salt', 20, 1000, 'nope'));
try {
    openssl_pbkdf2('password', 'salt', 0, 1000);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
