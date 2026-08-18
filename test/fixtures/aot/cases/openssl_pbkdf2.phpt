--TEST--
AOT: openssl_pbkdf2() SHA-256 key derivation (#32410 leftover of #6488, ext/openssl/openssl.c)
--FILE--
<?php
$derived = openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256');
echo ($derived === hex2bin('632c2812e46d4604102ba7618e9d6d7d2f8128f6')) ? "ok\n" : "bad\n";
echo strlen($derived), "\n";
--EXPECT--
ok
20
