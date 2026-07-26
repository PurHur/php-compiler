--TEST--
password_hash/password_verify named password/algo/hash arguments (JIT, issue #23207)
--FILE--
<?php
$h = password_hash(password: 'secret', algo: PASSWORD_DEFAULT);
echo password_verify(password: 'secret', hash: $h) ? "verify_ok\n" : "verify_fail\n";
echo password_verify(password: 'wrong', hash: $h) ? "wrong_ok\n" : "wrong_no\n";
--EXPECT--
verify_ok
wrong_no
