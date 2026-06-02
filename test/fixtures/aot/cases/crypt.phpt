--TEST--
AOT crypt() bcrypt round-trip (issue #3771)
--FILE--
<?php
$salt = '$2y$10$' . str_repeat('b', 22);
$hash = crypt('aot-secret', $salt);
echo strlen($hash) === 60 ? "len_ok\n" : "len_bad\n";
echo crypt('aot-secret', $hash) === $hash ? "verify_ok\n" : "verify_bad\n";
--EXPECT--
len_ok
verify_ok
