--TEST--
stdlib password_hash() — null $password coerces to empty string (#17023, ext/standard/password.c Z_PARAM_STR)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$hash = password_hash(null, PASSWORD_BCRYPT);
echo \is_string($hash) && str_starts_with($hash, '$2y$') ? "hash_ok\n" : "hash_fail\n";
echo password_verify('', $hash) ? "verify_ok\n" : "verify_fail\n";
--EXPECT--
hash_ok
verify_ok
