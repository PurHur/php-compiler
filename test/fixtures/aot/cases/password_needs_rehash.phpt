--TEST--
AOT password_needs_rehash() on password_hash() output (#3279)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]) ? "needs\n" : "ok\n";
echo password_needs_rehash($hash, PASSWORD_DEFAULT, []) ? "default_needs\n" : "default_ok\n";
echo password_needs_rehash('not-a-hash', PASSWORD_BCRYPT) ? "invalid_yes\n" : "invalid_no\n";
--EXPECT--
needs
default_ok
invalid_yes
