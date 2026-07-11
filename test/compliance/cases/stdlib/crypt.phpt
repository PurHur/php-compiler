--TEST--
stdlib crypt() — bcrypt salt round-trip (issue #3771)
--FILE--
<?php
echo function_exists('crypt') ? "exists\n" : "missing\n";
$salt = '$2y$10$' . str_repeat('a', 22);
$hash = crypt('secret', $salt);
echo strlen($hash) === 60 ? "len_ok\n" : "len_bad\n";
echo strncmp($hash, '$2y$10$', 7) === 0 ? "prefix_ok\n" : "prefix_bad\n";
echo crypt('secret', $hash) === $hash ? "verify_ok\n" : "verify_bad\n";
echo CRYPT_BLOWFISH === 1 ? "const_ok\n" : "const_bad\n";
echo defined('CRYPT_SHA256') && CRYPT_SHA256 === 1 ? "sha256_ok\n" : "sha256_bad\n";
echo defined('CRYPT_SHA512') && CRYPT_SHA512 === 1 ? "sha512_ok\n" : "sha512_bad\n";
--EXPECT--
exists
len_ok
prefix_ok
verify_ok
const_ok
sha256_ok
sha512_ok
