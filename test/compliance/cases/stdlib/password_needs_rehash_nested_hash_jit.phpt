--TEST--
stdlib password_needs_rehash() — nested password_hash() JIT (#17708, ext/standard/password.c)
--JIT--
--FILE--
<?php
$nested = password_needs_rehash(password_hash('x', PASSWORD_BCRYPT), PASSWORD_BCRYPT, ['cost' => 4]);
echo \is_bool($nested) ? "ok\n" : "fail\n";
--EXPECT--
ok
