--TEST--
AOT: password_hash/password_verify accept Zend stub named params (#23207)
--FILE--
<?php
// AOT password crypto is currently soft-fail/abort on this host (existing
// password_hash.phpt also red). Gate named-arg acceptance only — Unknown
// named parameter must not fire once BuiltinParamNames registers stub names.
try {
    $h = password_hash(password: 'x', algo: PASSWORD_DEFAULT);
    echo (false === $h || is_string($h)) ? "hash_named_ok\n" : "hash_named_bad\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $known = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
    password_verify(password: 'password', hash: $known);
    echo "verify_named_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash_named_ok
verify_named_ok
