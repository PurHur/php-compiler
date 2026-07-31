--TEST--
AOT PASSWORD_ARGON2* ConstFetch string algo ids (#25818, ext/standard/password.c)
--SKIPIF--
<?php if (!defined('PASSWORD_ARGON2ID')) { die('skip argon2 unavailable'); } ?>
--FILE--
<?php
// Assert ConstFetch identity (=== / echo). is_string()/gettype() on folded
// string constants are unreliable under AOT native strings; password_hash AOT
// verify is separately red on master (password_default_constant).
echo PASSWORD_ARGON2I === 'argon2i' ? "argon2i_ok\n" : "argon2i_bad\n";
echo PASSWORD_ARGON2ID === 'argon2id' ? "argon2id_ok\n" : "argon2id_bad\n";
echo PASSWORD_ARGON2I, "\n";
echo PASSWORD_ARGON2ID, "\n";
$algo = PASSWORD_ARGON2ID;
echo $algo === 'argon2id' ? "assign_ok\n" : "assign_bad\n";
--EXPECT--
argon2i_ok
argon2id_ok
argon2i
argon2id
assign_ok
