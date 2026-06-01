--TEST--
AOT: hash_pbkdf2() sha256 raw output (#3773)
--FILE--
<?php
$key = hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, true);
echo strlen($key), "\n";
echo bin2hex($key), "\n";
--EXPECT--
32
632c2812e46d4604102ba7618e9d6d7d2f8128f6266b4a03264d2a0460b7dcb3
