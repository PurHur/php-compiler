--TEST--
stdlib password_hash() — null $algo defaults to bcrypt (#18155, ext/standard/password.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$hash = password_hash('secret', null);
echo \is_string($hash) && str_starts_with($hash, '$2y$') ? "hash_ok\n" : "hash_fail\n";
echo strlen($hash) === 60 ? "len_ok\n" : "len_fail\n";
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
--EXPECT--
hash_ok
len_ok
verify_ok
