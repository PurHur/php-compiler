--TEST--
AOT: openssl_pbkdf2() SHA-256 key derivation including runtime args (#32410 leftover of #6488, ext/openssl/openssl.c)
--FILE--
<?php
$derived = openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256');
echo ($derived === hex2bin('632c2812e46d4604102ba7618e9d6d7d2f8128f6')) ? "ok\n" : "bad\n";
echo strlen($derived), "\n";
$pw = 'password';
$salt = 'salt';
$len = 20;
$iter = 1000;
$algo = 'sha256';
$runtime = openssl_pbkdf2($pw, $salt, $len, $iter, $algo);
echo ($runtime === hex2bin('632c2812e46d4604102ba7618e9d6d7d2f8128f6')) ? "runtimeok\n" : "runtimebad\n";
var_dump(openssl_pbkdf2('password', 'salt', 20, 0, 'sha256'));
var_dump(@openssl_pbkdf2('password', 'salt', 20, 1000, 'nope'));
try {
    openssl_pbkdf2('password', 'salt', 0, 1000);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ok
20
runtimeok
bool(false)
bool(false)
openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0
