--TEST--
stdlib hash_pbkdf2() null password/salt TypeError on 8.4 forward JIT (#20659, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    $r = hash_pbkdf2('sha256', null, 'salt', 1);
    echo 'password uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = hash_pbkdf2('sha256', 'p', null, 1);
    echo 'salt uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo var_export(hash_pbkdf2('sha256', '', 'salt', 1) !== '', true), "\n";
?>
--EXPECT--
hash_pbkdf2(): Argument #2 ($password) must be of type string, null given
hash_pbkdf2(): Argument #3 ($salt) must be of type string, null given
true
