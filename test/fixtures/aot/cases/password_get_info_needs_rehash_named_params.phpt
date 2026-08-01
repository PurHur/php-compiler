--TEST--
AOT: password_get_info/password_needs_rehash accept Zend stub named params (#23292)
--FILE--
<?php
// Gate named-arg acceptance — Unknown named parameter must not fire once
// BuiltinParamNames registers stub names. Crypto may soft-fail on some hosts.
try {
    $h = password_hash('x', PASSWORD_DEFAULT);
    if (!is_string($h)) {
        $h = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
    }
    $info = password_get_info(hash: $h);
    echo is_array($info) ? "info_named_ok\n" : "info_named_bad\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $known = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
    $r = password_needs_rehash(hash: $known, algo: PASSWORD_DEFAULT);
    echo is_bool($r) ? "rehash_named_ok\n" : "rehash_named_bad\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
info_named_ok
rehash_named_ok
