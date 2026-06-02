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
echo CRYPT_BLOWFISH === 4 ? "const_ok\n" : "const_bad\n";
--EXPECT--
exists
len_ok
prefix_ok
verify_ok
const_ok
