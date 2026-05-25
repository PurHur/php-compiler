--TEST--
stdlib password_hash() / password_verify() JIT path (#172)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
echo password_verify('wrong', $hash) ? "wrong_ok\n" : "wrong_no\n";

$hash2 = password_hash('other', PASSWORD_BCRYPT);
echo password_verify('other', $hash2) ? "bcrypt_ok\n" : "bcrypt_fail\n";
echo password_verify('nope', $hash2) ? "bcrypt_wrong\n" : "bcrypt_no\n";

echo password_hash('x', 99) === false ? "bad_algo\n" : "bad_algo_fail\n";
--EXPECT--
verify_ok
wrong_no
bcrypt_ok
bcrypt_no
bad_algo
