--TEST--
stdlib hash_pbkdf2() sha256 binary and hex (issue #3773)
--FILE--
<?php
$key = hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, true);
echo strlen($key), "\n";
echo bin2hex($key), "\n";
echo hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, false), "\n";
echo hash_pbkdf2('sha1', 'password', 'salt', 1000, 20, false), "\n";
try {
    hash_pbkdf2('nope', 'p', 's', 1, 32);
    echo "no error\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
32
632c2812e46d4604102ba7618e9d6d7d2f8128f6266b4a03264d2a0460b7dcb3
632c2812e46d4604102ba7618e9d6d7d
6e88be8bad7eae9d9e10
ValueError
