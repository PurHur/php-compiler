--TEST--
AOT password_hash() / password_verify() (#172)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
echo password_verify('wrong', $hash) ? "wrong_ok\n" : "wrong_no\n";

try {
    password_hash('x', 99);
    echo "bad_algo_fail\n";
} catch (ValueError $e) {
    echo "bad_algo\n";
}
--EXPECT--
verify_ok
wrong_no
bad_algo
