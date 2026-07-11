--TEST--
AOT PASSWORD_DEFAULT constant + password_hash() round-trip (#9275, ext/standard/password.c)
--FILE--
<?php
echo PASSWORD_DEFAULT, "\n";
echo PASSWORD_DEFAULT === '2y' ? "cmp_ok\n" : "cmp_fail\n";
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
--EXPECT--
2y
cmp_ok
verify_ok
