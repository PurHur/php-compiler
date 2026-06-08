--TEST--
stdlib password_hash()/password_verify() bootstrap — libcrypt native path (#4794)
--FILE--
<?php
$h = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
echo password_verify('secret', $h) ? "verify_ok\n" : "verify_fail\n";
echo str_starts_with($h, '$2y$') ? "prefix_ok\n" : "prefix_fail\n";
--EXPECT--
verify_ok
prefix_ok
