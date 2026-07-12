--TEST--
stdlib openssl_pbkdf2() — PBKDF2 key derivation (#6488, ext/openssl/kdf.c)
--FILE--
<?php
echo function_exists('openssl_pbkdf2') ? "exists\n" : "missing\n";
$derived = openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256');
echo bin2hex($derived), "\n";
echo bin2hex(openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256')), "\n";
var_dump(openssl_pbkdf2('password', 'salt', 20, 0, 'sha256'));
var_dump(@openssl_pbkdf2('password', 'salt', 20, 1000, 'nope'));
try {
    openssl_pbkdf2('password', 'salt', 0, 1000);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
exists
632c2812e46d4604102ba7618e9d6d7d2f8128f6
632c2812e46d4604102ba7618e9d6d7d2f8128f6
bool(false)
bool(false)
openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0
