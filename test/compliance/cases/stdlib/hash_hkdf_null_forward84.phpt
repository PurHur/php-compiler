--TEST--
stdlib hash_hkdf() null algo/key TypeError on 8.4 forward (#21079, ext/hash/hash.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = hash_hkdf('sha256', null);
    echo 'key uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = hash_hkdf(null, 'k');
    echo 'algo uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash_hkdf('sha256', '');
    echo "empty key uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_hkdf(): Argument #2 ($key) must be of type string, null given
hash_hkdf(): Argument #1 ($algo) must be of type string, null given
hash_hkdf(): Argument #2 ($key) cannot be empty
